<?php
/**
 * File: field_camp.php
 * Description: Field Camp Wizard, one camp: the steps Basics, Study area,
 *              Maps, Offline maps, Rock units, Accounts, Package and Student
 *              work (the roster: what each account has uploaded). Offline
 *              maps chooses the maps and the deepest zoom for the offline
 *              tiles and starts their build (FieldCampTiles); ?ajax=build
 *              answers the state of a running build. ?new=1 starts a camp (Basics only);
 *              ?id=N&step=... opens an existing one. Rock units lists the units
 *              Macrostrat maps show in the study area (FieldCampUnits); the
 *              selected ones become geologic unit tags of the project. Maps
 *              selects the owner's My Maps that go in as custom maps, and
 *              Package offers the project package (FieldCampPackage,
 *              field_camp_package.php). All changes post to field_camp_action.php.
 *              Nothing needs a separate "save": the Next button of a step
 *              saves and moves on, and leaving a step by the tabs with unsaved
 *              changes saves it first (forms marked data-fc-save, script at
 *              the end of the page).
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
require_once __DIR__ . '/includes/fieldcamp/FieldCampUnits.php';
require_once __DIR__ . '/includes/fieldcamp/FieldCampPackage.php';
require_once __DIR__ . '/includes/fieldcamp/FieldCampTiles.php';
require_once __DIR__ . '/includes/fieldcamp/FieldCampRoster.php';

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
	if($_GET['ajax'] === 'password'){
		// The camp's shared password, for the eye button on the Accounts step. Only sent when asked for.
		header('Cache-Control: no-store');
		echo json_encode(array('password' => FieldCamp::campPassword($camp)));
	}elseif($_GET['ajax'] === 'plan'){
		echo json_encode(FieldCamp::planAccounts($db, $camp, (string)($_GET['template'] ?? ''), (int)($_GET['count'] ?? 0)));
	}elseif($_GET['ajax'] === 'build' && FieldCampTiles::installed($db)){
		echo json_encode(FieldCampTiles::status($db, $camp));
	}else{
		echo json_encode(array());
	}
	exit();
}

$steps = array('basics' => 'Basics', 'area' => 'Study area', 'maps' => 'Maps', 'tiles' => 'Offline maps',
	'units' => 'Rock units', 'accounts' => 'Accounts', 'package' => 'Package', 'roster' => 'Student work');
// Later steps need their tables (the stage 3 and stage 4 parts of sql/fieldcamp.sql).
if(!FieldCampUnits::installed($db)) unset($steps['units']);
if(!FieldCampPackage::installed($db)){ unset($steps['maps']); unset($steps['package']); }
if(!isset($steps['package']) || !FieldCampTiles::installed($db)) unset($steps['tiles']);
/** The step after $key that this install has, as [key, label], or null at the end. */
function fc_next($steps, $key){
	$keys = array_keys($steps);
	$i = array_search($key, $keys, true);
	if($i === false || !isset($keys[$i + 1])) return null;
	return array($keys[$i + 1], $steps[$keys[$i + 1]]);
}
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
/** Student work: what the app reported about the package on this account's device ('' until any app reports). */
function fc_package_note($row, $current){
	if($current === null) return '';
	if($row['package_version'] === '') return ' <span class="fc-small">(package not loaded yet)</span>';
	$when = $row['package_installed'] ? ' ' . gmdate('Y-m-d', $row['package_installed']) : '';
	return $row['package_version'] === $current ? ' <span class="fc-small">(package loaded' . $when . ')</span>'
		: ' <span class="fc-small">(an older package loaded' . $when . ')</span>';
}

/** $goto: the step to open after a successful save ('' = stay on the step that posted). */
function fc_hidden($csrf, $action, $camp, $goto = ''){
	return '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">'
		. '<input type="hidden" name="goto" value="' . htmlspecialchars($goto) . '">'
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
$projectCounts = array(); $copiedCounts = array(); $toCopy = array(); $holdings = array();
if($accounts){
	require_once __DIR__ . '/includes/fieldcamp/FieldCampCopy.php';
	$copier = new FieldCampCopy($db, $neodb);
	$pks = array(); foreach($accounts as $a){ $pks[] = (int)$a->user_pkey; }
	$projectCounts = $copier->projectCounts($pks);
	$copiedCounts = $copier->copiedCounts($camp->pkey);
	// What each account holds, and whether the owner has a current copy of it (asked before anything is removed).
	$holdings = $copier->holdings($camp, $accounts);
	// Deactivated accounts that still hold work not copied to the owner (or changed since the copy).
	foreach($accounts as $a){
		$pk = (int)$a->user_pkey;
		if(!FieldCamp::truthy($a->active) && ($holdings[$pk]['uncopied'] ?? 0) > 0){
			$toCopy[] = array('user' => $pk, 'username' => $a->username);
		}
	}
}
// One account picked for removal on the Accounts step (?remove=<user pkey>): its confirmation panel.
$removeAccount = null;
if($accounts && isset($_GET['remove'])){
	foreach($accounts as $a){ if((int)$a->user_pkey === (int)$_GET['remove']) $removeAccount = $a; }
}
$removedAccounts = ($camp && $step === 'accounts' && FieldCamp::isAdmin($userpkey)) ? FieldCamp::listRemovedAccounts($db, $camp->pkey) : array();
// Deleting the camp (Basics step, ?delete=1): what would go.
$deleteAsk = ($camp && $step === 'basics' && isset($_GET['delete']) && FieldCamp::canDeleteCamps($db));
$deleteFacts = null;
if($deleteAsk){
	require_once __DIR__ . '/includes/fieldcamp/FieldCampCopy.php';
	$all = FieldCamp::listAccounts($db, $camp->pkey);
	$deleteFacts = array('accounts' => count($all), 'active' => 0, 'with_work' => 0, 'spots' => 0, 'photos' => 0, 'uncopied' => 0);
	foreach($all as $a){ if(FieldCamp::truthy($a->active)) $deleteFacts['active']++; }
	foreach((new FieldCampCopy($db, $neodb))->holdings($camp, $all) as $h){
		if($h['spots'] > 0) $deleteFacts['with_work']++;
		$deleteFacts['spots'] += $h['spots']; $deleteFacts['photos'] += $h['photos'];
		if($h['uncopied'] > 0) $deleteFacts['uncopied']++;
	}
}
$units = array(); $unitSources = array(); $chosenCount = 0;
if($camp && $step === 'units'){
	$units = FieldCampUnits::listUnits($db, $camp->pkey);
	$unitSources = FieldCampUnits::listSources($db, $camp->pkey);
	// After a refused save, show what was typed, not what is stored.
	$typed = array();
	if(isset($form['units']) && is_array($form['units'])){
		foreach($form['units'] as $row){ if(is_array($row) && isset($row['pkey'])) $typed[(int)$row['pkey']] = $row; }
	}
	foreach($units as $u){
		if(isset($typed[(int)$u->pkey])){
			$row = $typed[(int)$u->pkey];
			$u->chosen = !empty($row['chosen']);
			$u->tag_name = is_string($row['name'] ?? null) ? $row['name'] : $u->tag_name;
			$u->unit_label = is_string($row['label'] ?? null) ? $row['label'] : $u->unit_label;
		}
		if(FieldCamp::truthy($u->chosen)) $chosenCount++;
	}
}
$myMaps = array(); $packageMaps = array(); $packageTags = 0;
if($camp && $step === 'maps'){
	$myMaps = FieldCampPackage::listMyMaps($db, $camp);
	// After a refused save, show what was typed, not what is stored.
	$typedMaps = array();
	if(isset($form['maps']) && is_array($form['maps'])){
		foreach($form['maps'] as $row){ if(is_array($row) && isset($row['hash'])) $typedMaps[(string)$row['hash']] = $row; }
	}
	foreach($myMaps as $i => $m){
		if(!isset($typedMaps[$m['hash']])) continue;
		$myMaps[$i]['chosen'] = !empty($typedMaps[$m['hash']]['chosen']);
		if(is_string($typedMaps[$m['hash']]['title'] ?? null)) $myMaps[$i]['title'] = $typedMaps[$m['hash']]['title'];
	}
}
$tileLayers = array(); $tileChosen = array(); $tileOptions = array(); $tileZoom = null;
$tileStatus = array('status' => '', 'message' => '', 'percent' => null, 'elapsed' => null, 'tiles' => 0, 'maps' => 0, 'bytes' => 0); $tilesOutdated = '';
$tilesWanted = false; $tilesNeedBuild = false;
// A running tile build is shown on every step (the progress panel under the step tabs).
if($camp && isset($steps['tiles'])) $tileStatus = FieldCampTiles::status($db, $camp);
if($camp && isset($steps['tiles']) && ($step === 'tiles' || $step === 'package')){
	$tileLayers = FieldCampTiles::layers(FieldCampPackage::customMapsForCamp($db, $camp));
	$tileChosen = FieldCampTiles::chosenLayerIds($camp, $tileLayers);
	$tilesOutdated = FieldCampTiles::outdated($camp, FieldCampTiles::manifest($camp), $tileLayers);
	$tilesWanted = FieldCampTiles::wanted($camp, $tileLayers);
	$tilesNeedBuild = FieldCampTiles::needsBuild($camp, $tileLayers);
	$tileBbox = FieldCampUnits::campBbox($camp);
	if($tileBbox !== null){
		$picked = array(); foreach($tileChosen as $tid){ $picked[] = $tileLayers[$tid]; }
		$tileOptions = FieldCampTiles::options($tileBbox, $picked ? $picked : array_values($tileLayers));
		$tileZoom = (int)$camp->tiles_zoom > 0 ? (int)$camp->tiles_zoom : FieldCampTiles::defaultZoom($tileOptions);
	}
}
$roster = array('rows' => array(), 'uploaded' => 0, 'total' => 0, 'spots' => 0, 'photos' => 0);
$rosterTodo = array(); $rosterCopies = array(); $rosterPackage = null;
if($camp && $step === 'roster'){
	$roster = FieldCampRoster::forCamp($neodb, $camp, FieldCamp::listAccounts($db, $camp->pkey));
	// Once an app reports which package it loaded, the step says so per account (and whether it is the current one).
	foreach($roster['rows'] as $r){ if($r['package_version'] !== ''){ $rosterPackage = FieldCampPackage::version($db, $camp); break; } }
	// Each project's copy in the owner's account: none yet, up to date, or behind what the student has uploaded.
	require_once __DIR__ . '/includes/fieldcamp/FieldCampCopy.php';
	$copier = new FieldCampCopy($db, $neodb);
	$copyRows = $copier->copyRows($camp->pkey, (int)$camp->owner_user_pkey);
	foreach($roster['rows'] as $r){
		$needs = false;
		foreach($r['projects'] as $p){
			$key = $r['user_pkey'] . ':' . $p['id'];
			$row = $copyRows[$key] ?? null;
			if(!$row){ $state = 'none'; }
			elseif((string)$row->source_stamp !== '' && (string)$row->source_stamp === $copier->sourceStamp($r['user_pkey'], $p['id'])){ $state = 'current'; }
			else{ $state = 'behind'; }
			$rosterCopies[$key] = array('state' => $state, 'when' => $row ? strtotime((string)($row->updated_date ?: $row->created_date)) : null);
			if($state !== 'current') $needs = true;
		}
		if($needs) $rosterTodo[] = array('user' => $r['user_pkey'], 'username' => $r['username']);
	}
}
if($camp && $step === 'package'){
	$packageMaps = FieldCampPackage::listCampMaps($db, $camp->pkey);
	$packageTags = count(FieldCampUnits::listUnits($db, $camp->pkey, true));
}
$ownsCamp = $camp && (int)$camp->owner_user_pkey === (int)$userpkey;
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
// Offline maps being built: a progress panel on every step, kept up to date by the script at the end
// ===========================================================================
if($tileStatus['status'] === 'building'){
	$pct = $tileStatus['percent'];
	$el = (int)$tileStatus['elapsed'];
?>
		<div class="fc-flash fc-build" id="fcBuildBox" data-step="<?php echo htmlspecialchars($step); ?>" data-elapsed="<?php echo $el; ?>">
			<div class="fc-build-top">
				<span><span class="fc-spinner"></span><strong id="fcBuildHead">Building the offline maps...</strong></span>
				<span class="fc-build-nums"><span id="fcBuildPct"><?php echo $pct === null ? '' : (int)$pct . '%'; ?></span> &nbsp; <span id="fcBuildTime"></span></span>
			</div>
			<div class="fc-bar"><div class="fc-bar-fill" id="fcBuildBar" style="width:<?php echo $pct === null ? 0 : max(1, (int)$pct); ?>%"></div></div>
			<div id="fcBuildMsg"><?php echo htmlspecialchars($tileStatus['message']); ?></div>
			<div class="fc-small" id="fcBuildNote" style="margin-top:0.35em;"><?php
				if($step === 'package') echo 'The package can be downloaded as soon as the offline maps are built. This page updates by itself.';
				elseif($step === 'tiles') echo 'A large area can take a while. You can go on to the other steps; the build keeps running. The maps and the zoom can be changed when it is done.';
				else echo 'You can keep working on the other steps; the build keeps running. The package can be downloaded when it is done.';
			?></div>
		</div>
<?php
}

// ===========================================================================
// Basics
// ===========================================================================
if($step === 'basics'){
?>
		<div class="fc-panel">
			<h4>Basics</h4>
			<form method="post" action="/field_camp_action"<?php echo $isNew ? '' : ' data-fc-save="basics"'; ?>>
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
					<div class="fc-hint">The name of the StraboField project the students will work in.<?php
						echo $isNew ? ' It follows the camp name and year until you change it here.' : ''; ?></div>
				</div>
				<div class="fc-buttons">
<?php if($isNew){ ?>
					<button type="submit" class="button primary small">Create field camp</button>
<?php }else{ ?>
					<button type="submit" class="button primary small" data-fc-next="area">Next: Study area</button>
<?php } ?>
				</div>
			</form>
		</div>
<?php if($isNew){ ?>
		<script>
		// A new camp: the project name follows "camp name + year" (the year is not added when the name already
		// ends with it) until the user types a project name of their own. Emptying the field hands it back.
		// Not on an existing camp: its project may already be on students' devices.
		(function(){
			var name = document.getElementById('fcName'), year = document.getElementById('fcYear'), project = document.getElementById('fcProject');
			function suggested(){
				var n = name.value.trim().replace(/\s+/g, ' '), y = year.value.trim();
				if(n === '') return '';
				if(!/^\d{4}$/.test(y) || new RegExp('(^|\\D)' + y + '$').test(n)) return n;
				return n + ' ' + y;
			}
			// follow from the start only when the field is empty or already holds the suggestion (a refused save keeps what was typed)
			var following = project.value.trim() === '' || project.value.trim() === suggested();
			function follow(){ if(following) project.value = suggested().slice(0, project.maxLength > 0 ? project.maxLength : 120); }
			name.addEventListener('input', follow);
			year.addEventListener('input', follow);
			project.addEventListener('input', function(){ following = project.value.trim() === ''; });
			project.addEventListener('blur', function(){ if(project.value.trim() === ''){ following = true; follow(); } });
			follow();
		})();
		</script>
<?php } ?>
<?php if(!$isNew && FieldCamp::canDeleteCamps($db)){ ?>
		<div class="fc-panel fc-danger" id="fcDelete">
			<h4>Delete this field camp</h4>
<?php 	if(!$deleteAsk){ ?>
			<div class="fc-mapnote">Removes the camp and all of its accounts. You are asked to confirm first, and shown what would go.</div>
			<div class="fc-buttons">
				<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=basics&amp;delete=1#fcDelete" class="button small">Delete this field camp...</a>
			</div>
<?php 	}elseif($tileStatus['status'] === 'building'){ ?>
			<div class="fc-mapnote">The offline maps of this camp are being built right now. The camp can be deleted when the build is done.</div>
			<div class="fc-buttons"><a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=basics" class="button small">Cancel</a></div>
<?php 	}else{ $f = $deleteFacts; ?>
			<div class="fc-mapnote">Deleting <strong><?php echo htmlspecialchars($camp->camp_name); ?></strong> does this:</div>
			<ul class="fc-facts">
				<li>The camp disappears from your list, with its study area, maps, rock units and offline map tiles.</li>
<?php 		if($f['accounts'] > 0){ ?>
				<li><strong><?php echo (int)$f['accounts']; ?> account<?php echo $f['accounts'] === 1 ? '' : 's'; ?></strong> <?php echo $f['accounts'] === 1 ? 'is' : 'are'; ?> closed for good<?php
					echo $f['active'] > 0 ? ' (' . (int)$f['active'] . ' can still sign in today)' : ''; ?>. The usernames cannot be used again.</li>
				<li>Every device signed in with one of these accounts is signed out. Work that is only on a device and was never uploaded can no longer be uploaded.</li>
				<li><?php echo $f['with_work'] > 0
					? '<strong>' . (int)$f['with_work'] . ' account' . ($f['with_work'] === 1 ? ' has' : 's have') . ' uploaded work</strong>: ' . number_format($f['spots']) . ' spot' . ($f['spots'] === 1 ? '' : 's') . ' and ' . number_format($f['photos']) . ' photo' . ($f['photos'] === 1 ? '' : 's') . '.'
					: 'No account has uploaded any work.'; ?></li>
<?php 		}else{ ?>
				<li>This camp has no accounts.</li>
<?php 		} ?>
				<li>Copies of student work in <?php echo $ownsCamp ? 'your own account' : "the camp owner's account"; ?> are kept.</li>
				<li>Nothing is erased from the server: the StraboSpot team can bring the camp and its accounts back if this turns out to be a mistake.</li>
			</ul>
			<form method="post" action="/field_camp_action" data-fc-confirm="<?php echo htmlspecialchars(trim((string)$camp->camp_name)); ?>">
				<?php echo fc_hidden($csrf, 'camp_delete', $camp); ?>
<?php 		if($f['uncopied'] > 0){ ?>
				<div class="fc-flash-err">
					<strong><?php echo (int)$f['uncopied']; ?> account<?php echo $f['uncopied'] === 1 ? ' holds' : 's hold'; ?> work that has not been copied to <?php echo $ownsCamp ? 'you' : 'the owner'; ?></strong>
					(or that has changed since the copy). Copy it first:
					<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=roster">go to Student work</a>.
					<div style="margin-top:0.5em;"><input type="checkbox" name="nocopy" value="1" id="fcDelNoCopy" data-fc-need><label for="fcDelNoCopy">I do not need this work</label></div>
				</div>
<?php 		} ?>
				<div class="fc-field">
					<label for="fcDelConfirm">To confirm, type the name of this camp: <strong><?php echo htmlspecialchars($camp->camp_name); ?></strong></label>
					<input type="text" name="confirm" id="fcDelConfirm" autocomplete="off" data-fc-typed>
				</div>
				<div class="fc-buttons">
					<button type="submit" class="button primary small">Delete this field camp</button>
					<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=basics" class="button small">Cancel</a>
				</div>
			</form>
<?php 	} ?>
		</div>
<?php } ?>
<?php
}

// ===========================================================================
// Study area
// ===========================================================================
if($step === 'area'){
	$hasArea = ($size !== null);
	$next = fc_next($steps, 'area');
?>
		<style>
		.fc-handle { width: 14px; height: 14px; box-sizing: border-box; background: #fff; border: 2px solid #e44c65; border-radius: 50%; cursor: move; }
		.fc-map.fc-drawing, .fc-map.fc-drawing .leaflet-grab, .fc-map.fc-drawing .leaflet-interactive { cursor: crosshair; }
		.fc-map.fc-drawing { touch-action: none; }
		</style>
		<div class="fc-panel">
			<h4>Study area</h4>
			<div class="fc-mapnote">
				Move and zoom the map to where the camp will work. Then click <strong>Draw the study area</strong> and drag a box
				around the whole area. To adjust the box, drag one of its corners; to start over, draw it again.
			</div>
			<div class="fc-buttons" style="margin-bottom:0.75em;">
				<button type="button" class="button small" id="fcDrawBtn"><?php echo $hasArea ? 'Draw the study area again' : 'Draw the study area'; ?></button>
				<span class="fc-small" id="fcDrawHint" style="display:none;">Drag a box on the map: press where one corner should be and let go at the opposite corner.</span>
			</div>
			<div id="fcMap" class="fc-map"></div>
			<div class="fc-mapnote">
				Study area: <strong id="fcViewSize"><?php echo $hasArea ? '...' : 'not drawn yet'; ?></strong>
			</div>
			<form method="post" action="/field_camp_action" id="fcAreaForm" data-fc-save="area">
				<?php echo fc_hidden($csrf, 'area', $camp, $next[0]); ?>
				<input type="hidden" name="west" id="fcWest">
				<input type="hidden" name="south" id="fcSouth">
				<input type="hidden" name="east" id="fcEast">
				<input type="hidden" name="north" id="fcNorth">
				<div class="fc-buttons">
					<button type="submit" class="button primary small" data-fc-next="<?php echo $next[0]; ?>">Next: <?php echo $next[1]; ?></button>
				</div>
			</form>
		</div>

		<link rel="stylesheet" href="/assets/js/leaflet/leaflet.css" />
		<script src="/assets/js/leaflet/leaflet.js"></script>
		<script>
		// The study area is a box the user draws (2026-11-10; before, it was whatever the map window showed, and
		// the window's wide shape rarely fits a field area). Drag to draw, drag a corner to adjust.
		(function(){
			var saved = <?php echo $hasArea ? json_encode(array(
				'west' => (float)$camp->bbox_west, 'south' => (float)$camp->bbox_south,
				'east' => (float)$camp->bbox_east, 'north' => (float)$camp->bbox_north)) : 'null'; ?>;

			var el = document.getElementById('fcMap');
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

			var form = document.getElementById('fcAreaForm');
			var btn = document.getElementById('fcDrawBtn'), hint = document.getElementById('fcDrawHint');
			var style = {color: '#e44c65', weight: 2, fillOpacity: 0.08, interactive: false};
			var rect = null, handles = [], box = null;   // box: {west, south, east, north}
			var drawing = false, startLL = null, startPt = null, drawRect = null;

			function clamp(v, lo, hi){ return Math.max(lo, Math.min(hi, v)); }
			function boxOf(a, b){
				return {west: clamp(Math.min(a.lng, b.lng), -180, 180), east: clamp(Math.max(a.lng, b.lng), -180, 180),
					south: clamp(Math.min(a.lat, b.lat), -85, 85), north: clamp(Math.max(a.lat, b.lat), -85, 85)};
			}
			function boundsOf(bx){ return [[bx.south, bx.west], [bx.north, bx.east]]; }
			function corners(bx){ return [[bx.north, bx.west], [bx.north, bx.east], [bx.south, bx.east], [bx.south, bx.west]]; }

			function show(bx){
				document.getElementById('fcWest').value = bx.west.toFixed(6);
				document.getElementById('fcSouth').value = bx.south.toFixed(6);
				document.getElementById('fcEast').value = bx.east.toFixed(6);
				document.getElementById('fcNorth').value = bx.north.toFixed(6);
				var midlat = (bx.south + bx.north) / 2;
				var w = (bx.east - bx.west) * 111.32 * Math.cos(midlat * Math.PI / 180);
				var h = (bx.north - bx.south) * 111.32;
				var fmt = function(km){ return km >= 100 ? Math.round(km).toLocaleString() : km.toFixed(1); };
				document.getElementById('fcViewSize').textContent = fmt(w) + ' km wide, ' + fmt(h) + ' km tall';
			}
			// changed: the user did it (tells the wizard there is something to save)
			function setBox(bx, changed){
				box = bx;
				if(rect) rect.setBounds(boundsOf(bx)); else rect = L.rectangle(boundsOf(bx), style).addTo(map);
				var cs = corners(bx);
				if(!handles.length){
					cs.forEach(function(c, i){
						var m = L.marker(c, {draggable: true, keyboard: false, title: 'Drag to adjust the study area',
							icon: L.divIcon({className: 'fc-handle', iconSize: [14, 14]})}).addTo(map);
						// the corner opposite the one being dragged stays where it is
						m.on('drag', function(){ var o = corners(box)[(i + 2) % 4]; var bx2 = boxOf(m.getLatLng(), L.latLng(o[0], o[1])); rect.setBounds(boundsOf(bx2)); show(bx2); });
						m.on('dragend', function(){ var o = corners(box)[(i + 2) % 4]; setBox(boxOf(m.getLatLng(), L.latLng(o[0], o[1])), true); });
						handles.push(m);
					});
				}else{
					handles.forEach(function(m, i){ m.setLatLng(cs[i]); });
				}
				show(bx);
				btn.textContent = 'Draw the study area again';
				if(changed) form.dispatchEvent(new Event('change', {bubbles: true}));
			}

			function setDrawing(on){
				drawing = on;
				el.classList.toggle('fc-drawing', on);
				hint.style.display = on ? '' : 'none';
				btn.textContent = on ? 'Cancel drawing' : (box ? 'Draw the study area again' : 'Draw the study area');
				if(on) map.dragging.disable(); else map.dragging.enable();
				if(!on && drawRect){ map.removeLayer(drawRect); drawRect = null; }
				startLL = null;
			}
			btn.addEventListener('click', function(){ setDrawing(!drawing); });
			document.addEventListener('keydown', function(ev){ if(ev.key === 'Escape' && drawing) setDrawing(false); });

			// Pointer events, so a mouse, a finger and a pen all draw the same way.
			el.addEventListener('pointerdown', function(ev){
				if(!drawing || (ev.pointerType === 'mouse' && ev.button !== 0) || ev.target.closest('.leaflet-control')) return;
				ev.preventDefault(); ev.stopPropagation();
				startLL = map.mouseEventToLatLng(ev); startPt = [ev.clientX, ev.clientY];
				if(el.setPointerCapture) try{ el.setPointerCapture(ev.pointerId); }catch(e){}
			}, true);
			el.addEventListener('pointermove', function(ev){
				if(!drawing || !startLL) return;
				ev.preventDefault();
				var b = boundsOf(boxOf(startLL, map.mouseEventToLatLng(ev)));
				if(drawRect) drawRect.setBounds(b); else drawRect = L.rectangle(b, {color: '#e44c65', weight: 2, dashArray: '6 4', fillOpacity: 0.08, interactive: false}).addTo(map);
			}, true);
			function finish(ev){
				if(!drawing || !startLL) return;
				var from = startLL, moved = Math.max(Math.abs(ev.clientX - startPt[0]), Math.abs(ev.clientY - startPt[1]));
				var to = map.mouseEventToLatLng(ev);
				startLL = null;
				if(drawRect){ map.removeLayer(drawRect); drawRect = null; }
				if(ev.type === 'pointercancel' || moved < 8) return;   // a click, not a box: keep drawing
				setDrawing(false);
				setBox(boxOf(from, to), true);
			}
			el.addEventListener('pointerup', finish, true);
			el.addEventListener('pointercancel', finish, true);

			if(saved){
				setBox(saved, false);
				map.fitBounds(boundsOf(saved), {padding: [40, 40]});
			}else{
				map.setView([39.5, -98.5], 4);
			}
		})();
		</script>
<?php
}

// ===========================================================================
// Maps
// ===========================================================================
if($step === 'maps'){
	$hasArea = ($size !== null);
	// Three lists: the owner's maps that can be selected, public maps over the area, the owner's other maps.
	$inArea = array(); $publicMaps = array(); $elsewhere = array(); $ownCount = 0;
	foreach($myMaps as $m){
		if($m['public']){ $publicMaps[] = $m; continue; }
		$ownCount++;
		if(!$hasArea || $m['overlaps'] === true || $m['chosen']) $inArea[] = $m; else $elsewhere[] = $m;
	}
?>
		<div class="fc-panel">
			<h4>Custom maps</h4>
			<div class="fc-mapnote">
				Maps that cover the study area: your own from <a href="/geotiff">My Maps</a>, and maps other people have
				made public on <a href="/publicmaps" target="_blank" rel="noopener">Public Maps</a>. Each map you select is
				added to the students' project as a custom map, under the title you give it here.
				<?php echo $ownsCamp ? '' : 'These are the maps of the owner of the camp.'; ?>
			</div>
<?php if(!$hasArea){ ?>
			<div class="fc-small" style="margin-bottom:0.75em;">No study area is saved yet, so every map of your own is listed and no public maps.
				<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=area">Choose the study area</a> to see which maps cover it.</div>
<?php } ?>
<?php if(!$myMaps){ ?>
			<div class="fc-mapnote" style="margin:0;">No map covers the study area, and there are no maps in My Maps yet.
				Upload a GeoTIFF there, then come back to this step.</div>
<?php }elseif(!$inArea && !$publicMaps && $hasArea){ ?>
			<div class="fc-mapnote" style="margin:0;">No map covers the study area: none of your own <?php echo (int)$ownCount; ?>, and no public map.</div>
<?php } ?>
<?php if($myMaps){ ?>
			<form method="post" action="/field_camp_action" id="fcMapForm" style="margin:0;" data-fc-save="maps_save">
				<?php echo fc_hidden($csrf, 'maps_save', $camp); ?>
				<input type="hidden" name="maps_json" id="fcMapsJson" value="">
<?php
	foreach(array('in' => $inArea, 'public' => $publicMaps, 'out' => $elsewhere) as $group => $list){
		if(!$list) continue;
		$labels = array('in' => $hasArea ? 'Your maps in the study area' : 'Your maps',
			'public' => 'Public maps in the study area', 'out' => 'Your maps not in the study area');
?>
				<div class="fc-maphead"><?php echo $labels[$group]; ?> (<?php echo count($list); ?>)</div>
				<div class="fc-units">
<?php	if($group !== 'out'){ ?>
					<div class="fc-mapline fc-unit-head"><div></div><div>Title in the app</div><div>File</div><div>Uploaded</div></div>
<?php	}
		foreach($list as $m){
			$rowId = 'fcM' . htmlspecialchars($m['hash']);
			$selectable = ($group !== 'out');
?>
					<div class="fc-mapline<?php echo $selectable ? '' : ' fc-unit-off'; ?>"<?php echo $selectable ? ' data-hash="' . htmlspecialchars($m['hash']) . '"' : ''; ?>>
<?php		if($selectable){ ?>
						<div><input type="checkbox" class="fc-map-on" id="<?php echo $rowId; ?>"<?php echo $m['chosen'] ? ' checked' : ''; ?>><label for="<?php echo $rowId; ?>"></label></div>
						<div><input type="text" class="fc-map-title" maxlength="<?php echo FieldCampPackage::MAX_TITLE; ?>" value="<?php echo htmlspecialchars($m['title']); ?>" aria-label="Title"></div>
<?php		}else{ ?>
						<div></div>
						<div class="fc-unit-text"><?php echo $m['extent'] === null ? 'No location could be read from this map' : 'Outside the study area'; ?></div>
<?php		} ?>
						<div class="fc-unit-text"><a href="/geotiff/detail/<?php echo htmlspecialchars($m['hash']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($m['name']); ?></a><?php
							echo ($m['chosen'] && $m['overlaps'] === false) ? ' <span class="fc-small">(outside the study area)</span>' : ''; ?></div>
						<div class="fc-unit-text"><?php echo htmlspecialchars($m['uploaded']); ?></div>
					</div>
<?php		}
?>
				</div>
<?php
	}
?>
				<div class="fc-buttons" style="margin-top:1em;">
<?php $next = fc_next($steps, 'maps'); ?>
					<button type="submit" class="button primary small" data-fc-next="<?php echo $next[0]; ?>">Next: <?php echo $next[1]; ?></button>
				</div>
<?php if(isset($steps['tiles'])){ ?>
				<div class="fc-small" style="margin-top:0.75em;">The next step, Offline maps, saves these maps and the standard
					basemaps into the package so they work without a connection.</div>
<?php } ?>
			</form>
			<script>
			(function(){
				var form = document.getElementById('fcMapForm');
				var rows = Array.prototype.slice.call(form.querySelectorAll('.fc-mapline[data-hash]'));
				function shade(){ rows.forEach(function(r){ r.classList.toggle('fc-unit-off', !r.querySelector('.fc-map-on').checked); }); }
				rows.forEach(function(r){ r.querySelector('.fc-map-on').addEventListener('change', shade); });
				form.addEventListener('submit', function(){
					document.getElementById('fcMapsJson').value = JSON.stringify(rows.map(function(r){
						return {hash: r.getAttribute('data-hash'), chosen: r.querySelector('.fc-map-on').checked,
							title: r.querySelector('.fc-map-title').value};
					}));
				});
				shade();
			})();
			</script>
<?php }else{ ?>
<?php $next = fc_next($steps, 'maps'); ?>
			<div class="fc-buttons" style="margin-top:1em;">
				<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=<?php echo $next[0]; ?>" class="button small">Next: <?php echo $next[1]; ?></a>
			</div>
<?php } ?>
		</div>
<?php
}

// ===========================================================================
// Offline maps
// ===========================================================================
if($step === 'tiles'){
	$hasArea = ($size !== null);
	$fmtSize = function($bytes){
		return $bytes >= 1e9 ? number_format($bytes / 1e9, 1) . ' GB' : ($bytes >= 1e6 ? number_format($bytes / 1e6, $bytes >= 1e7 ? 0 : 1) . ' MB' : max(1, round($bytes / 1e3)) . ' kB');
	};
?>
<?php if(!$hasArea){ ?>
		<div class="fc-panel">
			<h4>Offline maps</h4>
			<div class="fc-mapnote">Choose the study area first. The offline maps are made for that area.</div>
			<div class="fc-buttons">
				<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=area" class="button primary small">Go to Study area</a>
			</div>
		</div>
<?php }else{ ?>
		<div class="fc-panel">
			<h4>Offline maps</h4>
			<div class="fc-mapnote">
				Map tiles that go into the project package, so the maps work in the field without a connection. Choose
				the maps and how far students can zoom in. Each deeper zoom level shows more detail and takes about four
				times the tiles.
			</div>

<?php $building = ($tileStatus['status'] === 'building');   // the progress panel above the step says so
	if($tileStatus['status'] === 'ready' || $tileStatus['status'] === 'failed'){ ?>
			<div class="<?php echo $tileStatus['status'] === 'failed' ? 'fc-flash-err' : 'fc-flash'; ?>" id="fcBuiltBox">
				<strong><?php
					echo $tileStatus['status'] === 'ready' ? 'Offline maps built' . ((string)$camp->build_finished !== '' ? ' ' . htmlspecialchars(substr((string)$camp->build_finished, 0, 10)) : '') . '.'
						: 'The last build did not finish.'; ?></strong>
				<span><?php echo htmlspecialchars($tileStatus['message']); ?></span>
<?php 	if($tileStatus['status'] === 'ready' && $tileStatus['bytes'] > 0){ ?>
				<span><?php echo $fmtSize($tileStatus['bytes']); ?>.</span>
<?php 	} ?>
<?php 	if($tileStatus['status'] === 'failed' && $tilesWanted){ ?>
				<span>Click Next to try again.</span>
<?php 	} ?>
			</div>
<?php } ?>
<?php if($tilesOutdated !== '' && $tilesWanted && !$building){ ?>
			<div class="fc-flash-err"><?php echo htmlspecialchars($tilesOutdated); ?> Click Next to build them again.</div>
<?php } ?>

			<form method="post" action="/field_camp_action" id="fcTileForm" style="margin:0;" data-fc-save="tiles_save">
				<?php echo fc_hidden($csrf, 'tiles_save', $camp, fc_next($steps, 'tiles')[0]); ?>
				<fieldset class="fc-tilefields"<?php echo $building ? ' disabled' : ''; ?>>
				<input type="hidden" name="layers_json" id="fcTileLayersJson" value="">
				<input type="hidden" name="offline" id="fcTileOffline" value="<?php echo $tilesWanted ? '1' : '0'; ?>">

				<div style="margin-top:0.5em;">
					<input type="checkbox" id="fcTilesOn"<?php echo $tilesWanted ? ' checked' : ''; ?>><label for="fcTilesOn">Include offline maps in the package</label>
				</div>
				<div id="fcTileBody"<?php echo $tilesWanted ? '' : ' style="display:none;"'; ?>>

				<div class="fc-maphead" style="margin-top:0.5em;">Maps</div>
				<div class="fc-units">
<?php foreach($tileLayers as $l){
		$on = in_array($l['id'], $tileChosen, true);
		$cid = 'fcT' . htmlspecialchars($l['id']);
?>
					<div class="fc-tileline" data-layer="<?php echo htmlspecialchars($l['id']); ?>">
						<div><input type="checkbox" class="fc-tile-on" id="<?php echo $cid; ?>"<?php echo $on ? ' checked' : ''; ?>><label for="<?php echo $cid; ?>"></label></div>
						<div><?php echo htmlspecialchars($l['name']); ?><?php echo $l['custom'] ? ' <span class="fc-small">(custom map)</span>' : ''; ?></div>
						<div class="fc-unit-text"><a href="#" class="fc-tile-show">Show in the preview</a></div>
					</div>
<?php } ?>
				</div>
<?php if(count($tileLayers) === count(FieldCampTiles::BASEMAPS)){ ?>
				<div class="fc-small" style="margin-top:0.5em;">Custom maps selected on the <a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=maps">Maps</a> step are listed here too.</div>
<?php } ?>

				<div class="fc-maphead">Deepest zoom</div>
				<div class="fc-units">
					<div class="fc-zoomline fc-unit-head"><div></div><div>Zoom</div><div>Scale</div><div>Tiles per map</div><div>Tiles in all</div><div>About</div></div>
<?php foreach($tileOptions as $o){
		$zid = 'fcZ' . (int)$o['zoom'];
?>
					<div class="fc-zoomline" data-zoom="<?php echo (int)$o['zoom']; ?>">
						<div><input type="radio" name="zoom" class="fc-zoom-on" id="<?php echo $zid; ?>" value="<?php echo (int)$o['zoom']; ?>"<?php
							echo (int)$o['zoom'] === (int)$tileZoom ? ' checked' : ''; ?>><label for="<?php echo $zid; ?>"></label></div>
						<div><?php echo (int)$o['zoom']; ?></div>
						<div class="fc-unit-text">1:<?php echo number_format($o['scale']); ?></div>
						<div class="fc-unit-text"><?php echo number_format($o['per_map']); ?></div>
						<div class="fc-unit-text fc-zoom-total"></div>
						<div class="fc-unit-text fc-zoom-size"></div>
					</div>
<?php } ?>
				</div>
				<div class="fc-small" id="fcTileWhy" style="margin-top:0.5em;"></div>

				<div class="fc-maphead">Preview</div>
				<div class="fc-small" style="margin-bottom:0.5em;">The closest view students will have without a connection, on
					<strong id="fcTilePreviewName"></strong>. The outline is the study area; tiles are saved for everything inside it.</div>
				<div id="fcTileMap" class="fc-map" style="height:420px;"></div>

				<div class="fc-small" style="margin-top:0.75em;">The sizes are estimates from typical tiles; the real size shows
					once the tiles are built. Zoom <?php echo FieldCampTiles::MAX_ZOOM; ?> is the deepest level offered for now.</div>
				</div>
				</fieldset>

				<div class="fc-mapnote" id="fcTileSummary" style="margin-top:1em;"></div>
				<div class="fc-buttons">
<?php $next = fc_next($steps, 'tiles'); ?>
					<button type="submit" class="button primary small" id="fcTileNextBtn" data-fc-next="<?php echo $next[0]; ?>">Next: <?php echo $next[1]; ?></button>
				</div>
			</form>
		</div>

		<link rel="stylesheet" href="/assets/js/leaflet/leaflet.css" />
		<script src="/assets/js/leaflet/leaflet.js"></script>
		<script>
		(function(){
			var cfg = <?php echo json_encode(array(
				'area' => FieldCampUnits::campBbox($camp),
				'options' => $tileOptions,
				'layers' => array_values($tileLayers),
				'maxPerMap' => FieldCampTiles::MAX_TILES_PER_MAP,
				'maxTotal' => FieldCampTiles::MAX_TILES_TOTAL,
				'building' => $building,
				'campId' => (int)$camp->pkey,
				// what is saved, and whether the built tiles match it: decides if Next will build
				'saved' => array('zoom' => (int)$camp->tiles_zoom, 'ids' => array_values($tileChosen)),
				'current' => ($tileStatus['status'] === 'ready' && $tileStatus['tiles'] > 0 && $tilesOutdated === ''),
				'hasBuilt' => ($tileStatus['status'] === 'ready' && $tileStatus['tiles'] > 0),
			)); ?>;
			var form = document.getElementById('fcTileForm');
			var layerRows = Array.prototype.slice.call(form.querySelectorAll('.fc-tileline'));
			var zoomRows = Array.prototype.slice.call(form.querySelectorAll('.fc-zoomline[data-zoom]'));
			var btn = document.getElementById('fcTileNextBtn');
			var onBox = document.getElementById('fcTilesOn'), body = document.getElementById('fcTileBody');

			function fmtSize(bytes){
				if(bytes >= 1e9) return (bytes / 1e9).toFixed(1) + ' GB';
				if(bytes >= 1e7) return Math.round(bytes / 1e6) + ' MB';
				if(bytes >= 1e6) return (bytes / 1e6).toFixed(1) + ' MB';
				return Math.max(1, Math.round(bytes / 1e3)) + ' kB';
			}
			function chosenLayers(){
				return cfg.layers.filter(function(l, i){ return layerRows[i].querySelector('.fc-tile-on').checked; });
			}
			function selectedZoom(){
				var r = form.querySelector('.fc-zoom-on:checked');
				return r ? parseInt(r.value, 10) : null;
			}

			// --- preview map ---------------------------------------------------
			var a = cfg.area, bounds = [[a.south, a.west], [a.north, a.east]];
			var map = L.map('fcTileMap', {minZoom: 2, zoomControl: false, scrollWheelZoom: false, doubleClickZoom: false,
				touchZoom: false, boxZoom: false, keyboard: false});
			L.rectangle(bounds, {color: '#e44c65', weight: 2, fill: false, interactive: false}).addTo(map);
			L.control.scale().addTo(map);
			var tileLayer = null, previewId = null;
			function tileUrl(l){
				return l.custom ? 'https://strabospot.org/geotiff/tiles/' + encodeURIComponent(l.id) + '/{z}/{x}/{y}.png'
					: 'https://tiles.strabospot.org/v5/' + l.id + '/{z}/{x}/{y}.png';
			}
			function showLayer(id){
				var l = cfg.layers.filter(function(x){ return x.id === id; })[0] || cfg.layers[0];
				if(tileLayer) map.removeLayer(tileLayer);
				tileLayer = L.tileLayer(tileUrl(l), {maxZoom: 19, noWrap: true}).addTo(map);
				previewId = l.id;
				document.getElementById('fcTilePreviewName').textContent = l.name;
			}
			function showZoom(){
				var z = selectedZoom();
				if(z === null) map.fitBounds(bounds);
				else map.setView([(a.south + a.north) / 2, (a.west + a.east) / 2], z);
			}

			// --- numbers --------------------------------------------------------
			function refresh(){
				var layers = chosenLayers(), kb = 0;
				layers.forEach(function(l){ kb += l.kb; });
				var z = selectedZoom(), why = '', summary = '';
				zoomRows.forEach(function(row, i){
					var o = cfg.options[i];
					var total = o.per_map * layers.length;
					var reason = '';
					if(o.per_map > cfg.maxPerMap) reason = 'Zoom ' + o.zoom + ' needs more than ' + cfg.maxPerMap.toLocaleString() + ' tiles per map. Use a smaller study area for this zoom.';
					else if(total > cfg.maxTotal) reason = 'Zoom ' + o.zoom + ' needs more than ' + cfg.maxTotal.toLocaleString() + ' tiles in all. Select fewer maps or use a smaller study area.';
					var radio = row.querySelector('.fc-zoom-on');
					radio.disabled = reason !== '';
					row.classList.toggle('fc-unit-off', reason !== '');
					row.querySelector('.fc-zoom-total').textContent = layers.length ? total.toLocaleString() : '';
					row.querySelector('.fc-zoom-size').textContent = layers.length ? fmtSize(o.per_map * kb * 1000) : '';
					if(o.zoom === z){
						if(reason) why = reason;
						else if(layers.length) summary = layers.length + ' map' + (layers.length === 1 ? '' : 's') + ' to zoom ' + o.zoom + ': '
							+ total.toLocaleString() + ' tiles, about ' + fmtSize(o.per_map * kb * 1000) + '.';
					}
				});
				if(!layers.length) why = 'Select at least one map, or switch the offline maps off.';
				else if(z === null) why = 'Choose a zoom level.';
				layerRows.forEach(function(r){ r.classList.toggle('fc-unit-off', !r.querySelector('.fc-tile-on').checked); });
				document.getElementById('fcTileWhy').textContent = why;

				// What Next will do, said before it is clicked.
				var on = onBox.checked, text = '';
				document.getElementById('fcTileOffline').value = on ? '1' : '0';
				if(cfg.building){
					text = 'The offline maps are being built now (see the progress above). Next takes you on while the build runs.';
				}else if(!on){
					text = 'The package will have no offline maps.' + (cfg.hasBuilt ? ' Next removes the tiles that were built.' : '');
				}else if(why !== ''){
					text = why;
				}else{
					var ids = layers.map(function(l){ return l.id; }).sort().join(',');
					var same = cfg.current && z === cfg.saved.zoom && ids === cfg.saved.ids.slice().sort().join(',');
					text = summary + (same ? ' These tiles are built and up to date.'
						: ' Next starts building them and takes you on; the build runs in the background and its progress shows on every step.');
				}
				document.getElementById('fcTileSummary').textContent = text;
				btn.disabled = !cfg.building && on && why !== '';
			}
			onBox.addEventListener('change', function(){
				body.style.display = onBox.checked ? '' : 'none';
				if(onBox.checked){
					// switched on with nothing selected: start from every map
					if(!chosenLayers().length) layerRows.forEach(function(r){ r.querySelector('.fc-tile-on').checked = true; });
					map.invalidateSize(); showZoom();
				}
				refresh();
			});

			layerRows.forEach(function(r){
				r.querySelector('.fc-tile-on').addEventListener('change', refresh);
				r.querySelector('.fc-tile-show').addEventListener('click', function(ev){ ev.preventDefault(); showLayer(r.getAttribute('data-layer')); });
			});
			zoomRows.forEach(function(r){ r.querySelector('.fc-zoom-on').addEventListener('change', function(){ refresh(); showZoom(); }); });
			form.addEventListener('submit', function(){
				document.getElementById('fcTileLayersJson').value = JSON.stringify(chosenLayers().map(function(l){ return l.id; }));
			});
			showLayer((chosenLayers()[0] || cfg.layers[0]).id);
			showZoom();
			refresh();

		})();
		</script>
<?php } ?>
<?php
}

// ===========================================================================
// Package
// ===========================================================================
if($step === 'package'){
?>
		<div class="fc-panel">
			<h4>Project package</h4>
			<div class="fc-mapnote">
				One file that sets up the project on a student's device. It is the same kind of file StraboField makes
				with "Backup", "Save &amp; Zip", so it loads the same way.
			</div>
			<div class="strabotable" style="margin-left:0px;">
				<table class="fc-table">
					<tr><td>Project name</td><td><?php echo htmlspecialchars($camp->project_name); ?></td></tr>
					<tr><td>Dataset</td><td>Default (empty)</td></tr>
					<tr><td>Geologic unit tags</td><td><?php echo (int)$packageTags; ?><?php
						echo $packageTags === 0 ? ' &nbsp; <a href="/field_camp?id=' . (int)$camp->pkey . '&amp;step=units">Choose rock units</a>' : ''; ?></td></tr>
					<tr><td>Custom maps</td><td><?php echo count($packageMaps); ?><?php
						$t = array(); foreach($packageMaps as $m){ $t[] = htmlspecialchars($m->title); }
						echo $t ? ': ' . implode(', ', $t) : ''; ?></td></tr>
					<tr><td>Offline map tiles</td><td><?php
						if($tileStatus['status'] === 'ready' && $tileStatus['tiles'] > 0){
							$b = $tileStatus['bytes'];
							echo number_format($tileStatus['tiles']) . ' tiles in ' . (int)$tileStatus['maps'] . ' map' . ((int)$tileStatus['maps'] === 1 ? '' : 's') . ', '
								. ($b >= 1e9 ? number_format($b / 1e9, 1) . ' GB' : number_format($b / 1e6, $b >= 1e7 ? 0 : 1) . ' MB');
							if($tilesOutdated !== '') echo ' &nbsp; <span class="fc-preview-msg fc-bad">' . htmlspecialchars($tilesOutdated) . '</span>';
						}elseif($tileStatus['status'] === 'building'){
							echo 'Being built now (progress above)';
						}elseif(isset($steps['tiles']) && !$tilesWanted){
							echo 'None (offline maps are switched off)';
						}elseif($tileStatus['status'] === 'failed'){
							echo '<span class="fc-preview-msg fc-bad">None: the last build did not finish</span>';
						}elseif(isset($steps['tiles'])){
							echo '<span class="fc-preview-msg fc-bad">None: not built yet</span>';
						}else{
							echo 'None';
						}
						if(isset($steps['tiles'])) echo ' &nbsp; <a href="/field_camp?id=' . (int)$camp->pkey . '&amp;step=tiles">Offline maps</a>';
					?></td></tr>
				</table>
			</div>
<?php
	// The offline maps decide what the download button offers: wait while they are built, and say so
	// plainly when the package would come without them or with older ones.
	$tilesUrl = '/field_camp?id=' . (int)$camp->pkey . '&amp;step=tiles';
	if($tileStatus['status'] === 'building'){ ?>
			<div class="fc-buttons" style="margin-top:1em;">
				<button type="button" class="button primary small" id="fcPackageWait" disabled>Download the project package</button>
				<span class="fc-small">Available as soon as the offline maps are built.</span>
			</div>
<?php }elseif($tilesNeedBuild){
		$hasOld = ($tileStatus['status'] === 'ready' && $tileStatus['tiles'] > 0); ?>
			<div class="fc-flash-err" style="margin-top:1em;" id="fcPackageTilesWarn">
				<strong><?php echo $hasOld ? 'The offline maps are out of date.' : ($tileStatus['status'] === 'failed' ? 'The offline maps could not be built.' : 'The offline maps have not been built yet.'); ?></strong>
				<?php echo $hasOld ? htmlspecialchars($tilesOutdated) . ' A package downloaded now holds the older tiles.'
					: 'A package downloaded now has no offline maps, so the maps will not work in the field without a connection.'; ?>
				Open the Offline maps step and click Next to build them, then come back here.
			</div>
			<div class="fc-buttons">
				<a href="<?php echo $tilesUrl; ?>" class="button primary small">Go to Offline maps</a>
				<a href="/field_camp_package?id=<?php echo (int)$camp->pkey; ?>" class="button small"><?php echo $hasOld ? 'Download with the older offline maps' : 'Download without offline maps'; ?></a>
			</div>
<?php }else{ ?>
			<div class="fc-buttons" style="margin-top:1em;">
				<a href="/field_camp_package?id=<?php echo (int)$camp->pkey; ?>" class="button primary small">Download the project package</a>
			</div>
<?php } ?>
			<div class="fc-small" style="margin-top:1em;">
				The package always holds what is saved on the other steps at the moment you download it. If you change the
				rock units or maps after students have loaded it, download it again; devices that already hold the project
				keep the older version.
			</div>
		</div>

		<div class="fc-panel" id="fcPackageHowTo">
			<h4>Getting the project onto the students' devices</h4>
			<div class="fc-mapnote">
				Every student loads the same package. Today that is done by hand, as in past camps; it takes a few minutes per device.
			</div>
			<div class="fc-maphead">Today: send the package to each device</div>
			<ol class="fc-steps-list">
				<li><strong>Download the package</strong> with the button above. It is one .zip file. Do not unzip it.</li>
				<li><strong>Send it to each device.</strong> From a Mac, AirDrop it to the iPad and choose "Save to Files" when the iPad asks
					what to do with it. Any other way of getting a file onto the device works too: a shared cloud folder, a link, a cable.
					With offline maps the file can be large, so do this on a good connection before the camp leaves for the field.</li>
				<li><strong>Sign in to StraboField on the device</strong> with that student's camp account: the username from the
					<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=accounts">Accounts</a> step and the camp password.
					Sign in before loading the package, and stay signed in for the whole camp: signing out removes the offline maps
					from the device and can lose work that has not been uploaded.</li>
				<li><strong>Load the package in StraboField.</strong> Open the main menu, choose StraboField Projects and, under
					"Load a Project", choose <strong>Import</strong>. Pick the .zip file, then choose <strong>Unzip and Save</strong>.</li>
				<li><strong>Open the project.</strong> Choose "Go to saved projects" and open
					<strong><?php echo htmlspecialchars($camp->project_name); ?></strong>.</li>
				<li><strong>Check it</strong> before going out: the geologic unit tags are there, the custom maps are listed, and the
					offline maps show with the connection switched off.</li>
				<li><strong>During the camp</strong> students upload their project from StraboField whenever they have a connection.
					What they have uploaded shows on the <a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=roster">Student work</a> step.</li>
			</ol>
			<div class="fc-maphead">How it is meant to work: StraboField offers the project at sign-in</div>
			<div class="fc-mapnote">
				Steps 1, 2, 4 and 5 are meant to disappear. The plan:
			</div>
			<ol class="fc-steps-list">
				<li>The student signs in to StraboField with the camp account.</li>
				<li>StraboField sees that this account belongs to a field camp and asks: "Your instructor has prepared the project
					<?php echo htmlspecialchars($camp->project_name); ?>. Download it now?"</li>
				<li>One tap downloads the package from StraboSpot and loads it: project, unit tags, custom maps and offline maps.</li>
				<li>The <a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=roster">Student work</a> step then shows which students have loaded the project, and whether anyone still holds an older package.</li>
			</ol>
			<div class="fc-mapnote" style="margin-bottom:0;">
				<strong>Where this stands:</strong> StraboSpot already offers the package to the app this way, for every account of this camp.
				StraboField does not ask for it yet; that needs an update of the app. Until then, use the steps under "Today".
				Nothing has to be set up again when the app update arrives: the same accounts and the same package are used.
			</div>
		</div>
<?php
}

// ===========================================================================
// Student work (roster)
// ===========================================================================
if($step === 'roster'){
?>
		<div class="fc-panel">
			<h4>Student work</h4>
<?php if(!$roster['rows']){ ?>
			<div class="fc-mapnote" style="margin:0;">This camp has no accounts yet. Create them on the
				<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=accounts">Accounts</a> step.</div>
<?php }else{ ?>
			<div class="fc-mapnote">
				<strong><?php echo (int)$roster['uploaded']; ?></strong> of <?php echo (int)$roster['total']; ?> students have uploaded the camp project:
				<?php echo number_format($roster['spots']); ?> spot<?php echo $roster['spots'] === 1 ? '' : 's'; ?> and
				<?php echo number_format($roster['photos']); ?> photo<?php echo $roster['photos'] === 1 ? '' : 's'; ?> in all.
				This shows what is on the server; work that is still only on a student's device does not appear until they upload.
			</div>
			<div class="fc-mapnote">
				To look at a student's work, copy it into <?php echo $ownsCamp ? 'your own account' : "the camp owner's account"; ?>: each student's project
				appears there as "<?php echo htmlspecialchars($camp->project_name); ?> (username)", on the website and in StraboField.
				A copy shows the work as it was when it was made; copy again to bring it up to date. The students' own projects are never changed.
			</div>
			<div class="fc-mapnote">
				For a quick look without a copy, <strong>Field Book</strong> opens a PDF of the student's project as it is on the server right now:
				spots by day with maps, photos, stereonets and notes. Photos the student has not uploaded yet are left out.
			</div>
			<div class="fc-buttons" style="margin-bottom:1em;">
<?php 	if($rosterTodo){ ?>
				<button type="button" class="button primary small" id="fcCopyBtn">Copy the work of <?php echo count($rosterTodo); ?> student<?php echo count($rosterTodo) === 1 ? '' : 's'; ?> that is new or has changed</button>
<?php 	}elseif($roster['uploaded'] > 0){ ?>
				<span class="fc-small">Every copy is up to date.</span>
<?php 	} ?>
<?php 	if($ownsCamp && $rosterCopies){ ?>
				<a href="/my_field_data" class="button small">Open My Field Data</a>
<?php 	} ?>
			</div>
			<div class="fc-preview" id="fcCopyLog" style="display:none; max-width:none;"></div>
			<div class="strabotable" style="margin-left:0px;">
				<table class="fc-table">
					<tr><td>Username</td><td>Project</td><td>Last upload</td><td>Datasets</td><td>Spots</td><td>Photos</td><td><?php echo $ownsCamp ? 'Your copy' : "Owner's copy"; ?></td><td>Field Book</td></tr>
<?php 	foreach($roster['rows'] as $r){
			if(!$r['projects']){ ?>
					<tr>
						<td><?php echo htmlspecialchars($r['username']); ?><?php echo $r['active'] ? '' : ' <span class="fc-small">(deactivated)</span>'; ?><?php echo fc_package_note($r, $rosterPackage); ?></td>
						<td colspan="7" class="fc-small">Nothing uploaded yet</td>
					</tr>
<?php 		}
			foreach($r['projects'] as $i => $p){ ?>
					<tr>
						<td><?php echo $i === 0 ? htmlspecialchars($r['username']) . ($r['active'] ? '' : ' <span class="fc-small">(deactivated)</span>') . fc_package_note($r, $rosterPackage) : ''; ?></td>
						<td><?php echo htmlspecialchars($p['name'] !== '' ? $p['name'] : 'Unnamed project'); ?><?php echo $p['camp'] ? '' : ' <span class="fc-small">(not the camp project)</span>'; ?></td>
						<td><?php echo $p['uploaded'] ? '<span class="fc-when" data-t="' . (int)$p['uploaded'] . '">' . gmdate('Y-m-d H:i', $p['uploaded']) . ' UTC</span>' : ''; ?></td>
						<td><?php echo (int)$p['datasets']; ?></td>
						<td><?php echo number_format($p['spots']); ?></td>
						<td><?php echo number_format($p['photos']); ?><?php echo $p['photos_missing'] > 0 ? ' <span class="fc-small">(' . (int)$p['photos_missing'] . ' not uploaded yet)</span>' : ''; ?></td>
						<td><?php
							$c = $rosterCopies[$r['user_pkey'] . ':' . $p['id']] ?? array('state' => 'none', 'when' => null);
							$when = $c['when'] ? '<span class="fc-when" data-t="' . (int)$c['when'] . '">' . gmdate('Y-m-d H:i', $c['when']) . ' UTC</span>' : '';
							if($c['state'] === 'current') echo 'Up to date <span class="fc-small">(' . $when . ')</span>';
							elseif($c['state'] === 'behind') echo '<span class="fc-preview-msg fc-bad">Changed since the copy</span> <span class="fc-small">(' . $when . ')</span>';
							else echo '<span class="fc-small">Not copied</span>';
						?></td>
						<td><?php
							// Straight from the student's account (fieldbook_build.php, Field Camp door); a project without spots has no book.
							if($p['spots'] > 0) echo '<a href="/fieldbook_build?camp=' . (int)$camp->pkey . '&amp;account=' . (int)$r['user_pkey'] . '&amp;project=' . rawurlencode($p['id']) . '" target="_blank" rel="noopener" class="fc-book">Open</a>';
							else echo '<span class="fc-small">No spots yet</span>';
						?></td>
					</tr>
<?php 		}
		} ?>
				</table>
			</div>
<?php 	if($rosterTodo){ ?>
			<script>
			// One account per request, so a long copy shows its progress and one failure does not stop the rest.
			(function(){
				var todo = <?php echo json_encode($rosterTodo); ?>;
				var btn = document.getElementById('fcCopyBtn');
				var log = document.getElementById('fcCopyLog');
				function line(text, bad){
					var d = document.createElement('div');
					if(bad) d.className = 'fc-taken';
					d.textContent = text;
					log.appendChild(d);
					log.scrollTop = log.scrollHeight;
				}
				function next(i, failed){
					if(i >= todo.length){
						line(failed ? 'Finished, with ' + failed + ' student(s) not copied.' : 'Finished.', failed > 0);
						setTimeout(function(){ window.location.reload(); }, failed ? 4000 : 1200);
						return;
					}
					var a = todo[i];
					var body = new URLSearchParams();
					body.append('csrf', <?php echo json_encode($csrf); ?>);
					body.append('action', 'copy_account');
					body.append('id', '<?php echo (int)$camp->pkey; ?>');
					body.append('user', a.user);
					fetch('/field_camp_action', {method: 'POST', body: body, credentials: 'same-origin'})
						.then(function(r){ return r.json(); })
						.then(function(res){
							(res.copied || []).forEach(function(c){
								line(c.name + (c.updated ? ' (updated): ' : ': ') + c.datasets + ' dataset(s), ' + c.spots + ' spot(s), ' + c.images + ' photo(s)'
									+ (c.missing_images ? ' (' + c.missing_images + ' photo(s) not uploaded by the student yet)' : ''));
							});
							if(res.error){ line(res.error, true); next(i + 1, failed + 1); return; }
							if(!(res.copied || []).length) line(a.username + ': nothing new');
							next(i + 1, failed);
						})
						.catch(function(){ line(a.username + ': the copy did not finish.', true); next(i + 1, failed + 1); });
				}
				btn.addEventListener('click', function(){
					btn.disabled = true;
					log.style.display = '';
					line('Copying ' + todo.length + ' student(s). Keep this page open...');
					next(0, 0);
				});
			})();
			</script>
<?php 	} ?>
			<script>
			// Upload times in the reader's own time zone.
			Array.prototype.forEach.call(document.querySelectorAll('.fc-when'), function(el){
				var d = new Date(parseInt(el.getAttribute('data-t'), 10) * 1000);
				el.textContent = d.toLocaleString([], {year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'});
			});
			</script>
<?php } ?>
		</div>
<?php
}

// ===========================================================================
// Rock units
// ===========================================================================
if($step === 'units'){
	$hasArea = ($size !== null);
	$fetched = isset($camp->units_fetched_date) && (string)$camp->units_fetched_date !== '';
	$areaChanged = $fetched && FieldCampUnits::areaChanged($camp);
	// Units grouped by the source map they come from, in map order.
	$bySource = array();
	foreach($units as $u){ $bySource[(int)$u->source_id][] = $u; }
?>
		<div class="fc-panel">
			<h4>Rock units</h4>
<?php if(!$hasArea){ ?>
			<div class="fc-mapnote">Choose the study area first. The rock units are looked up for that area.</div>
			<div class="fc-buttons">
				<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=area" class="button primary small">Go to Study area</a>
			</div>
<?php }else{ ?>
			<div class="fc-mapnote">
				The rock units that geologic maps show inside the study area, from
				<a href="https://macrostrat.org" target="_blank" rel="noopener">Macrostrat</a>.
				Each unit you select becomes a geologic unit tag in the students' project, with its age,
				rock type, description and map color filled in.
			</div>
<?php if($areaChanged){ ?>
			<div class="fc-flash-err">The study area has changed since these units were looked up. Look them up again to match the new area.</div>
<?php } ?>
<?php if($fetched && (int)$camp->units_zoom < FieldCampUnits::DETAIL_ZOOM){ ?>
			<div class="fc-small" style="margin-bottom:0.75em;">
				The study area is too large for Macrostrat's most detailed maps, so these units come from a
				less detailed map. A smaller study area gives more detailed units.
			</div>
<?php } ?>
			<form method="post" action="/field_camp_action" id="fcUnitFetchForm" style="margin:0;">
				<?php echo fc_hidden($csrf, 'units_fetch', $camp); ?>
				<input type="hidden" name="units_json" id="fcUnitsJsonFetch" value="">
				<div class="fc-buttons">
					<button type="submit" class="button <?php echo $fetched ? '' : 'primary '; ?>small" id="fcUnitFetchBtn"><?php echo $fetched ? 'Look up the units again' : 'Find the rock units of the study area'; ?></button>
<?php if($fetched){ ?>
					<span class="fc-small">Last looked up <?php echo htmlspecialchars(substr((string)$camp->units_fetched_date, 0, 10)); ?>.
						Looking up again keeps your selection, names and labels.</span>
<?php } ?>
				</div>
			</form>
<?php } ?>
		</div>

<?php if($hasArea && $fetched && !$units){ ?>
		<div class="fc-panel">
			<div class="fc-mapnote" style="margin:0;">Macrostrat has no mapped rock units in this study area.
				Geologic unit tags can still be added by hand in StraboField.</div>
		</div>
<?php } ?>

<?php if($units){ ?>
		<form method="post" action="/field_camp_action" id="fcUnitForm" data-fc-save="units_save">
			<?php echo fc_hidden($csrf, 'units_save', $camp); ?>
			<input type="hidden" name="units_json" id="fcUnitsJson" value="">
<?php foreach($bySource as $sourceId => $list){
		$src = $unitSources[$sourceId] ?? null;
		$srcName = $src && (string)$src->name !== '' ? $src->name : 'Macrostrat source map ' . $sourceId;
?>
			<div class="fc-panel fc-unit-source">
				<h4><?php echo htmlspecialchars($srcName); ?> <span class="fc-small">(<?php echo count($list); ?> unit<?php echo count($list) === 1 ? '' : 's'; ?>)</span></h4>
<?php if($src && (string)$src->ref_title !== ''){ ?>
				<div class="fc-small" style="margin-bottom:0.5em;"><?php echo htmlspecialchars($src->ref_title); ?></div>
<?php } ?>
				<div class="fc-small" style="margin-bottom:0.75em;">
					<a href="#" class="fc-unit-all" data-on="1">Select all</a> &nbsp;&middot;&nbsp;
					<a href="#" class="fc-unit-all" data-on="0">Select none</a>
				</div>
				<div class="fc-units">
					<div class="fc-unit fc-unit-head">
						<div></div><div></div><div>Label</div><div>Tag name</div><div>Age</div><div>Lithology</div>
					</div>
<?php foreach($list as $u){
			$on = FieldCamp::truthy($u->chosen);
?>
					<div class="fc-unit" data-pkey="<?php echo (int)$u->pkey; ?>"<?php echo (string)$u->descrip !== '' ? ' title="' . htmlspecialchars($u->descrip) . '"' : ''; ?>>
						<div><input type="checkbox" class="fc-unit-on" id="fcU<?php echo (int)$u->pkey; ?>"<?php echo $on ? ' checked' : ''; ?>><label for="fcU<?php echo (int)$u->pkey; ?>"></label></div>
						<div><span class="fc-chip"<?php echo (string)$u->color !== '' ? ' style="background:' . htmlspecialchars($u->color) . ';"' : ''; ?>></span></div>
						<div><input type="text" class="fc-unit-label" maxlength="<?php echo FieldCampUnits::MAX_LABEL; ?>" value="<?php echo htmlspecialchars($u->unit_label); ?>" aria-label="Unit label"></div>
						<div><input type="text" class="fc-unit-name" maxlength="<?php echo FieldCampUnits::MAX_NAME; ?>" value="<?php echo htmlspecialchars($u->tag_name); ?>" aria-label="Tag name"></div>
						<div class="fc-unit-text"><?php echo htmlspecialchars((string)$u->age); ?></div>
						<div class="fc-unit-text"><?php echo htmlspecialchars((string)$u->lith); ?></div>
					</div>
<?php } ?>
				</div>
			</div>
<?php } ?>
			<div class="fc-panel">
				<div class="fc-mapnote"><strong id="fcUnitCount"><?php echo (int)$chosenCount; ?></strong> of <?php echo count($units); ?> units selected.
					The label is the short code a geologic map prints on the unit (for example Kd); the ones here are a first guess.
					Hold the pointer over a unit to read its description.</div>
				<div class="fc-buttons">
<?php $next = fc_next($steps, 'units'); ?>
					<button type="submit" class="button primary small" data-fc-next="<?php echo $next[0]; ?>">Next: <?php echo $next[1]; ?></button>
				</div>
				<div class="fc-small" style="margin-top:0.75em;">Rock unit data from Macrostrat (macrostrat.org), CC-BY 4.0.
					Each tag's notes name the source map.</div>
			</div>
		</form>
		<script>
		(function(){
			var form = document.getElementById('fcUnitForm');
			var rows = Array.prototype.slice.call(form.querySelectorAll('.fc-unit[data-pkey]'));
			function count(){
				var n = 0;
				rows.forEach(function(r){
					var on = r.querySelector('.fc-unit-on').checked;
					if(on) n++;
					r.classList.toggle('fc-unit-off', !on);
				});
				document.getElementById('fcUnitCount').textContent = n;
			}
			rows.forEach(function(r){ r.querySelector('.fc-unit-on').addEventListener('change', count); });
			Array.prototype.forEach.call(form.querySelectorAll('.fc-unit-all'), function(a){
				a.addEventListener('click', function(ev){
					ev.preventDefault();
					var on = a.getAttribute('data-on') === '1';
					Array.prototype.forEach.call(a.closest('.fc-unit-source').querySelectorAll('.fc-unit-on'), function(c){ c.checked = on; });
					count();
				});
			});
			// One field for the whole list: a large area can hold more units than PHP accepts as separate inputs.
			function listJson(){
				return JSON.stringify(rows.map(function(r){
					return {
						pkey: parseInt(r.getAttribute('data-pkey'), 10),
						chosen: r.querySelector('.fc-unit-on').checked,
						label: r.querySelector('.fc-unit-label').value,
						name: r.querySelector('.fc-unit-name').value
					};
				}));
			}
			form.addEventListener('submit', function(){ document.getElementById('fcUnitsJson').value = listJson(); });
			// "Look up the units again" takes what is on the page along, so nothing typed here is lost by it.
			var fetchForm = document.getElementById('fcUnitFetchForm');
			if(fetchForm) fetchForm.addEventListener('submit', function(){ document.getElementById('fcUnitsJsonFetch').value = listJson(); });
			count();
		})();
		</script>
<?php } ?>
		<script>
		(function(){
			var f = document.getElementById('fcUnitFetchForm');
			if(!f) return;
			f.addEventListener('submit', function(){
				var b = document.getElementById('fcUnitFetchBtn');
				b.disabled = true;
				b.textContent = 'Asking Macrostrat...';
			});
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
<?php $pwKept = FieldCamp::campPassword($camp) !== null; ?>
			<div class="fc-field">
				<label>Password of every account</label>
<?php if($pwKept){ ?>
				<div class="fc-pw">
					<code id="fcPwText" data-shown="0">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</code>
					<button type="button" class="fc-eye" id="fcPwEye" aria-label="Show the password" title="Show the password">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/><line id="fcPwSlash" x1="3" y1="3" x2="21" y2="21" style="display:none;"/></svg>
					</button>
				</div>
				<script>
				(function(){
					var text = document.getElementById('fcPwText'), eye = document.getElementById('fcPwEye'), slash = document.getElementById('fcPwSlash');
					var dots = text.textContent;
					eye.addEventListener('click', function(){
						if(text.getAttribute('data-shown') === '1'){
							text.textContent = dots; text.setAttribute('data-shown', '0'); slash.style.display = 'none';
							eye.setAttribute('aria-label', 'Show the password'); eye.title = 'Show the password';
							return;
						}
						fetch('/field_camp?id=<?php echo (int)$camp->pkey; ?>&ajax=password', {credentials: 'same-origin'})
							.then(function(r){ return r.json(); })
							.then(function(j){
								if(!j.password) return;
								text.textContent = j.password; text.setAttribute('data-shown', '1'); slash.style.display = '';
								eye.setAttribute('aria-label', 'Hide the password'); eye.title = 'Hide the password';
							});
					});
				})();
				</script>
<?php }else{ ?>
				<div class="fc-hint" style="margin-top:0;">The password of this camp is not on record: its accounts were made before the
					wizard kept passwords. If you remember it, type it once under "Add more accounts" and it will be kept from then on.</div>
<?php } ?>
			</div>
			<div class="strabotable" style="margin-left:0px;">
				<table class="fc-table">
					<tr><td>Username</td><td>Created</td><td>Status</td><td>Projects uploaded</td><td>Copied to <?php echo $ownsCamp ? 'you' : 'the owner'; ?></td><td></td></tr>
<?php foreach($accounts as $a){ ?>
					<tr<?php echo ($removeAccount && (int)$removeAccount->user_pkey === (int)$a->user_pkey) ? ' class="fc-row-picked"' : ''; ?>>
						<td><?php echo htmlspecialchars($a->username); ?></td>
						<td><?php echo htmlspecialchars(substr((string)$a->created_date, 0, 10)); ?></td>
						<td><?php echo FieldCamp::truthy($a->active) ? 'Active' : 'Deactivated'; ?></td>
						<td><?php echo (int)($projectCounts[(int)$a->user_pkey] ?? 0); ?></td>
						<td><?php echo (int)($copiedCounts[(int)$a->user_pkey] ?? 0); ?></td>
						<td><a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=accounts&amp;remove=<?php echo (int)$a->user_pkey; ?>#fcRemove" class="fc-small">Remove...</a></td>
					</tr>
<?php } ?>
				</table>
			</div>
		</div>
<?php } ?>

<?php if($removeAccount){
	$rpk = (int)$removeAccount->user_pkey;
	$h = $holdings[$rpk] ?? array('projects' => 0, 'spots' => 0, 'photos' => 0, 'uncopied' => 0);
	$stay = '/field_camp?id=' . (int)$camp->pkey . '&amp;step=accounts';
?>
		<div class="fc-panel fc-danger" id="fcRemove">
			<h4>Remove the account <?php echo htmlspecialchars($removeAccount->username); ?></h4>
			<ul class="fc-facts">
				<li>The account is closed for good<?php echo FieldCamp::truthy($removeAccount->active) ? ' (it can still sign in today)' : ''; ?>, and its username cannot be used again.</li>
				<li>Its device is signed out. Work that is only on the device and was never uploaded can no longer be uploaded.</li>
				<li><?php echo $h['projects'] > 0
					? 'On the server it holds <strong>' . (int)$h['projects'] . ' project' . ($h['projects'] === 1 ? '' : 's') . '</strong>: ' . number_format($h['spots']) . ' spot' . ($h['spots'] === 1 ? '' : 's') . ' and ' . number_format($h['photos']) . ' photo' . ($h['photos'] === 1 ? '' : 's') . '.'
					: 'It has not uploaded anything.'; ?></li>
				<li>Nothing is erased from the server: the StraboSpot team can bring the account back if this turns out to be a mistake.</li>
			</ul>
			<form method="post" action="/field_camp_action" data-fc-confirm="<?php echo htmlspecialchars((string)$removeAccount->username); ?>">
				<?php echo fc_hidden($csrf, 'account_remove', $camp); ?>
				<input type="hidden" name="user" value="<?php echo $rpk; ?>">
<?php 	if($h['uncopied'] > 0){ ?>
				<div class="fc-flash-err">
					<strong>This account holds work that has not been copied to <?php echo $ownsCamp ? 'you' : 'the owner'; ?></strong> (or that has changed since the copy).
					<div class="fc-buttons" style="margin-top:0.5em;">
						<button type="button" class="button small" id="fcRemoveCopyBtn">Copy this student's work first</button>
						<span class="fc-small" id="fcRemoveCopyMsg"></span>
					</div>
					<div style="margin-top:0.5em;"><input type="checkbox" name="nocopy" value="1" id="fcRemNoCopy" data-fc-need><label for="fcRemNoCopy">I do not need this work</label></div>
				</div>
				<script>
				(function(){
					var btn = document.getElementById('fcRemoveCopyBtn'), msg = document.getElementById('fcRemoveCopyMsg');
					btn.addEventListener('click', function(){
						btn.disabled = true; msg.textContent = 'Copying. Keep this page open...';
						var body = new URLSearchParams();
						body.append('csrf', <?php echo json_encode($csrf); ?>);
						body.append('action', 'copy_account');
						body.append('id', '<?php echo (int)$camp->pkey; ?>');
						body.append('user', '<?php echo $rpk; ?>');
						fetch('/field_camp_action', {method: 'POST', body: body, credentials: 'same-origin'})
							.then(function(r){ return r.json(); })
							.then(function(res){
								if(res.error){ msg.textContent = res.error; btn.disabled = false; return; }
								window.location.reload();
							})
							.catch(function(){ msg.textContent = 'The copy did not finish. Please try again.'; btn.disabled = false; });
					});
				})();
				</script>
<?php 	}elseif($h['spots'] > 0){ ?>
				<div class="fc-flash"><?php echo $ownsCamp ? 'You have' : 'The owner has'; ?> an up-to-date copy of this account's work.</div>
<?php 	} ?>
				<div class="fc-field">
					<label for="fcRemConfirm">To confirm, type the username: <strong><?php echo htmlspecialchars($removeAccount->username); ?></strong></label>
					<input type="text" name="confirm" id="fcRemConfirm" autocomplete="off" data-fc-typed>
				</div>
				<div class="fc-buttons">
					<button type="submit" class="button primary small">Remove this account</button>
					<a href="<?php echo $stay; ?>" class="button small">Cancel</a>
				</div>
			</form>
		</div>
<?php } ?>

<?php if($removedAccounts){ ?>
		<div class="fc-panel">
			<h4>Removed accounts (admin)</h4>
			<div class="fc-mapnote">Removed accounts keep their data on the server. A restored account comes back deactivated.</div>
			<div class="strabotable" style="margin-left:0px;">
				<table class="fc-table">
					<tr><td>Username</td><td>Removed</td><td></td></tr>
<?php 	foreach($removedAccounts as $a){ ?>
					<tr>
						<td><?php echo htmlspecialchars($a->username); ?></td>
						<td><?php echo htmlspecialchars(substr((string)$a->removed_date, 0, 16)); ?></td>
						<td><form method="post" action="/field_camp_action" style="margin:0;">
							<?php echo fc_hidden($csrf, 'account_restore', $camp); ?>
							<input type="hidden" name="user" value="<?php echo (int)$a->user_pkey; ?>">
							<button type="submit" class="button small">Restore</button>
						</form></td>
					</tr>
<?php 	} ?>
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
<?php if($liveCount > 0 && FieldCamp::campPassword($camp) !== null){ ?>
				<div class="fc-field">
					<div class="fc-hint" style="margin-top:0;">The new accounts get the password the camp's accounts already use.</div>
				</div>
<?php }else{ ?>
				<div class="fc-field">
					<label for="fcPassword">Password for <?php echo $liveCount > 0 ? 'these accounts' : 'every account'; ?></label>
					<input type="text" name="password" id="fcPassword" autocomplete="off" maxlength="72">
					<div class="fc-hint">
<?php if($liveCount > 0){ ?>
						Enter the password the camp's accounts already use, so every account matches.
<?php }else{ ?>
						At least <?php echo FieldCamp::MIN_PASSWORD_LENGTH; ?> characters. Every account of the camp gets this
						password, and it cannot be changed afterwards. You can look it up on this page at any time.
						Every student will know it, so do not use a password you use anywhere else.
<?php } ?>
					</div>
				</div>
<?php } ?>
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
<?php if($toCopy){ ?>
			<div class="fc-field" style="margin-top:1.5em;">
				<label>Keep the students' work</label>
				<div class="fc-hint" style="margin-bottom:0.5em;">
					Copies every project of the <?php echo count($toCopy); ?> deactivated account(s) that has not been copied yet
					into <?php echo $ownsCamp ? 'your account' : "the camp owner's account"; ?>, named after the student
					(for example "<?php echo htmlspecialchars($camp->project_name . ' (' . $toCopy[0]['username'] . ')'); ?>").
					The students' own projects are not changed. Do this before removing the accounts.
				</div>
				<div class="fc-buttons">
					<button type="button" class="button small" id="fcCopyBtn">Copy student projects</button>
				</div>
				<div class="fc-preview" id="fcCopyLog" style="display:none; max-width:none;"></div>
			</div>
			<script>
			(function(){
				var todo = <?php echo json_encode($toCopy); ?>;
				var btn = document.getElementById('fcCopyBtn');
				var log = document.getElementById('fcCopyLog');
				function line(text, bad){
					var d = document.createElement('div');
					if(bad) d.className = 'fc-taken';
					d.textContent = text;
					log.appendChild(d);
					log.scrollTop = log.scrollHeight;
				}
				function next(i, failed){
					if(i >= todo.length){
						line(failed ? 'Finished, with ' + failed + ' account(s) not copied. Reload the page to see the result.'
							: 'Finished. Reload the page to see the result.', failed > 0);
						return;
					}
					var a = todo[i];
					var body = new URLSearchParams();
					body.append('csrf', <?php echo json_encode($csrf); ?>);
					body.append('action', 'copy_account');
					body.append('id', '<?php echo (int)$camp->pkey; ?>');
					body.append('user', a.user);
					fetch('/field_camp_action', {method: 'POST', body: body, credentials: 'same-origin'})
						.then(function(r){ return r.json(); })
						.then(function(res){
							(res.copied || []).forEach(function(c){
								line(c.name + ': ' + c.datasets + ' dataset(s), ' + c.spots + ' spot(s), ' + c.images + ' photo(s)'
									+ (c.missing_images ? ' (' + c.missing_images + ' photo(s) were never uploaded by the student)' : ''));
							});
							if(res.error){ line(res.error, true); next(i + 1, failed + 1); return; }
							if(!(res.copied || []).length) line(a.username + ': nothing new to copy');
							next(i + 1, failed);
						})
						.catch(function(){ line(a.username + ': the copy did not finish. Reload the page and try again.', true); next(i + 1, failed + 1); });
				}
				btn.addEventListener('click', function(){
					btn.disabled = true;
					log.style.display = '';
					line('Copying ' + todo.length + ' account(s). Keep this page open...');
					next(0, 0);
				});
			})();
			</script>
<?php } ?>
<?php
	$goSpots = 0; $goPhotos = 0; $goWork = 0;
	foreach($accounts as $a){
		if(FieldCamp::truthy($a->active)) continue;
		$gh = $holdings[(int)$a->user_pkey] ?? null;
		if($gh){ $goSpots += $gh['spots']; $goPhotos += $gh['photos']; if($gh['spots'] > 0) $goWork++; }
	}
?>
			<form method="post" action="/field_camp_action" style="margin-top:1.5em;" data-fc-confirm="<?php echo htmlspecialchars(trim((string)$camp->camp_name)); ?>">
				<?php echo fc_hidden($csrf, 'accounts_remove', $camp); ?>
				<div class="fc-field">
					<label for="fcConfirm">Remove the <?php echo (int)($liveCount - $activeCount); ?> deactivated account(s)</label>
					<div class="fc-hint" style="margin-bottom:0.5em;">
						Removed accounts are closed for good and their usernames cannot be used again.
						<?php echo $goWork > 0 ? (int)$goWork . ' of them ' . ($goWork === 1 ? 'has' : 'have') . ' uploaded work (' . number_format($goSpots) . ' spot' . ($goSpots === 1 ? '' : 's') . ', ' . number_format($goPhotos) . ' photo' . ($goPhotos === 1 ? '' : 's') . ').' : 'None of them has uploaded work.'; ?>
						Work that is only on a device can no longer be uploaded. Nothing is erased from the server: the StraboSpot team can bring an account back.
					</div>
<?php if($toCopy){ ?>
					<div class="fc-flash-err">
						<strong><?php echo count($toCopy); ?> of them still hold work that has not been copied.</strong>
						Copy it first with "Copy student projects" above.
						<div style="margin-top:0.5em;"><input type="checkbox" name="nocopy" value="1" id="fcAllNoCopy" data-fc-need><label for="fcAllNoCopy">I do not need this work</label></div>
					</div>
<?php } ?>
					<div class="fc-hint" style="margin-bottom:0.5em;">To confirm, type the name of this camp: <strong><?php echo htmlspecialchars($camp->camp_name); ?></strong></div>
					<input type="text" name="confirm" id="fcConfirm" autocomplete="off" data-fc-typed>
				</div>
				<div class="fc-buttons"><button type="submit" class="button small">Remove deactivated accounts</button></div>
			</form>
<?php } ?>
		</div>
<?php } ?>

<?php if(isset($steps['package'])){ ?>
		<div class="fc-buttons" style="margin-bottom:1.5em;">
			<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=package" class="button small">Next: Package</a>
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

		<script>
		// Strict confirmation (removing accounts, deleting the camp): the button stays off until the name
		// asked for is typed exactly and, where work has not been copied, "I do not need this work" is selected.
		// The server checks the same things again.
		Array.prototype.forEach.call(document.querySelectorAll('form[data-fc-confirm]'), function(form){
			var want = form.getAttribute('data-fc-confirm');
			var typed = form.querySelector('[data-fc-typed]'), need = form.querySelector('[data-fc-need]');
			var btn = form.querySelector('button[type=submit]');
			function check(){ btn.disabled = !(typed.value.trim() === want && (!need || need.checked)); }
			typed.addEventListener('input', check);
			if(need) need.addEventListener('change', check);
			check();
		});
		</script>

<?php if($camp && $tileStatus['status'] === 'building'){ ?>
		<script>
		// Offline maps being built: keep the progress panel under the step tabs up to date. When the build
		// ends, the Offline maps and Package steps load again (they show what was built); any other step
		// only changes the panel, so nothing typed there is lost.
		(function(){
			var box = document.getElementById('fcBuildBox');
			if(!box) return;
			var campId = <?php echo (int)$camp->pkey; ?>, step = box.getAttribute('data-step');
			var elapsed = parseInt(box.getAttribute('data-elapsed'), 10) || 0, tick = Date.now(), done = false;
			function fmt(sec){ sec = Math.max(0, Math.round(sec)); var m = Math.floor(sec / 60), s = sec % 60; return (m ? m + ' min ' : '') + s + ' s'; }
			function clock(){ if(!done) document.getElementById('fcBuildTime').textContent = fmt(elapsed + (Date.now() - tick) / 1000); }
			function finish(s){
				done = true;
				if(step === 'tiles' || step === 'package'){ window.location.href = '/field_camp?id=' + campId + '&step=' + step; return; }
				var ok = s.status === 'ready';
				box.className = ok ? 'fc-flash' : 'fc-flash-err';
				box.innerHTML = '';
				var b = document.createElement('strong');
				b.textContent = ok ? 'The offline maps are built. ' : 'The offline maps could not be built. ';
				box.appendChild(b);
				box.appendChild(document.createTextNode((s.message || '') + ' '));
				var a = document.createElement('a');
				a.href = '/field_camp?id=' + campId + '&step=' + (ok ? 'package' : 'tiles');
				a.textContent = ok ? 'Go to Package' : 'Go to Offline maps';
				box.appendChild(a);
			}
			function poll(){
				fetch('/field_camp?id=' + campId + '&ajax=build&_=' + Date.now(), {credentials: 'same-origin'})
					.then(function(r){ return r.json(); })
					.then(function(s){
						if(s.status !== 'building'){ finish(s); return; }
						document.getElementById('fcBuildMsg').textContent = s.message || '';
						if(s.percent !== null && s.percent !== undefined){
							document.getElementById('fcBuildPct').textContent = s.percent + '%';
							document.getElementById('fcBuildBar').style.width = Math.max(1, s.percent) + '%';
						}
						if(s.elapsed !== null && s.elapsed !== undefined){ elapsed = s.elapsed; tick = Date.now(); }
						setTimeout(poll, 2000);
					})
					.catch(function(){ setTimeout(poll, 4000); });
			}
			clock(); setInterval(clock, 1000);
			setTimeout(poll, 1500);
		})();
		</script>
<?php } ?>

		<script>
		// No separate "save": a step's Next button saves and moves on, and leaving by the step tabs with
		// unsaved changes saves first. Anything else that would lose changes (closing the page) asks.
		(function(){
			var form = document.querySelector('form[data-fc-save]');
			var leaving = false, dirty = false;
			document.addEventListener('submit', function(){ leaving = true; }, true);
			if(!form) return;
			function mark(){ dirty = true; }
			form.addEventListener('input', mark);
			form.addEventListener('change', mark);
			form.addEventListener('click', function(ev){ if(ev.target.closest('a, button, label, input')) mark(); });
			function saveAndGo(step){
				form.querySelector('input[name=goto]').value = step;
				form.querySelector('input[name=action]').value = form.getAttribute('data-fc-save');
			}
			Array.prototype.forEach.call(form.querySelectorAll('[data-fc-next]'), function(b){
				b.addEventListener('click', function(){ saveAndGo(b.getAttribute('data-fc-next')); });
			});
			Array.prototype.forEach.call(document.querySelectorAll('.fc-steps a'), function(a){
				var m = /[?&]step=([a-z]+)/.exec(a.getAttribute('href') || '');
				if(!m) return;
				a.addEventListener('click', function(ev){
					if(!dirty) return;
					ev.preventDefault();
					saveAndGo(m[1]);
					if(form.requestSubmit) form.requestSubmit(); else form.submit();
				});
			});
			window.addEventListener('beforeunload', function(ev){
				if(dirty && !leaving){ ev.preventDefault(); ev.returnValue = ''; }
			});
		})();
		</script>

		<div class="bottomSpacer"></div>
	</div>
</div>

<?php
include("includes/mfooter.php");
?>
