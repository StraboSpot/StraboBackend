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
	if($_GET['ajax'] === 'plan'){
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
$projectCounts = array(); $copiedCounts = array(); $toCopy = array();
if($accounts){
	require_once __DIR__ . '/includes/fieldcamp/FieldCampCopy.php';
	$copier = new FieldCampCopy($db, $neodb);
	$pks = array(); foreach($accounts as $a){ $pks[] = (int)$a->user_pkey; }
	$projectCounts = $copier->projectCounts($pks);
	$copiedCounts = $copier->copiedCounts($camp->pkey);
	// Deactivated accounts that still hold a project not yet copied to the owner.
	foreach($accounts as $a){
		$pk = (int)$a->user_pkey;
		if(!FieldCamp::truthy($a->active) && ($projectCounts[$pk] ?? 0) > ($copiedCounts[$pk] ?? 0)){
			$toCopy[] = array('user' => $pk, 'username' => $a->username);
		}
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
$tileStatus = array('status' => '', 'message' => '', 'tiles' => 0, 'maps' => 0, 'bytes' => 0); $tilesOutdated = '';
if($camp && isset($steps['tiles']) && ($step === 'tiles' || $step === 'package')){
	$tileLayers = FieldCampTiles::layers(FieldCampPackage::customMapsForCamp($db, $camp));
	$tileChosen = FieldCampTiles::chosenLayerIds($camp, $tileLayers);
	$tileStatus = FieldCampTiles::status($db, $camp);
	$tilesOutdated = FieldCampTiles::outdated($camp, FieldCampTiles::manifest($camp), $tileLayers);
	$tileBbox = FieldCampUnits::campBbox($camp);
	if($tileBbox !== null){
		$picked = array(); foreach($tileChosen as $tid){ $picked[] = $tileLayers[$tid]; }
		$tileOptions = FieldCampTiles::options($tileBbox, $picked ? $picked : array_values($tileLayers));
		$tileZoom = (int)$camp->tiles_zoom > 0 ? (int)$camp->tiles_zoom : FieldCampTiles::defaultZoom($tileOptions);
	}
}
$roster = array('rows' => array(), 'uploaded' => 0, 'total' => 0, 'spots' => 0, 'photos' => 0);
$rosterTodo = array(); $rosterCopies = array();
if($camp && $step === 'roster'){
	$roster = FieldCampRoster::forCamp($neodb, $camp, FieldCamp::listAccounts($db, $camp->pkey));
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
					<div class="fc-hint">The name of the StraboField project the students will work in.</div>
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
<?php $next = fc_next($steps, 'area'); ?>
				<?php echo fc_hidden($csrf, 'area', $camp, $hasArea ? '' : $next[0]); ?>
				<input type="hidden" name="west" id="fcWest">
				<input type="hidden" name="south" id="fcSouth">
				<input type="hidden" name="east" id="fcEast">
				<input type="hidden" name="north" id="fcNorth">
				<div class="fc-buttons">
<?php if($hasArea){ ?>
					<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=<?php echo $next[0]; ?>" class="button primary small">Next: <?php echo $next[1]; ?></a>
					<button type="submit" class="button small">Replace the study area with this view</button>
<?php }else{ ?>
					<button type="submit" class="button primary small">Use this view as the study area</button>
<?php } ?>
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

<?php if($tileStatus['status'] !== ''){ ?>
			<div class="<?php echo $tileStatus['status'] === 'failed' ? 'fc-flash-err' : 'fc-flash'; ?>" id="fcBuildBox">
				<strong id="fcBuildHead"><?php
					echo $tileStatus['status'] === 'building' ? 'Building the offline tiles...'
						: ($tileStatus['status'] === 'ready' ? 'Offline tiles built' . ((string)$camp->build_finished !== '' ? ' ' . htmlspecialchars(substr((string)$camp->build_finished, 0, 10)) : '') . '.'
						: 'The last build did not finish.'); ?></strong>
				<span id="fcBuildMsg"><?php echo htmlspecialchars($tileStatus['message']); ?></span>
<?php 	if($tileStatus['status'] === 'ready' && $tileStatus['bytes'] > 0){ ?>
				<span><?php echo $fmtSize($tileStatus['bytes']); ?>.</span>
<?php 	} ?>
<?php 	if($tileStatus['status'] === 'building'){ ?>
				<div class="fc-small" style="margin-top:0.35em;">This takes a few minutes. You can leave this page; the build goes on.</div>
<?php 	} ?>
			</div>
<?php } ?>
<?php if($tilesOutdated !== ''){ ?>
			<div class="fc-flash-err"><?php echo htmlspecialchars($tilesOutdated); ?> Build them again to bring the package up to date.</div>
<?php } ?>

			<form method="post" action="/field_camp_action" id="fcTileForm" style="margin:0;" data-fc-save="tiles_save">
				<?php echo fc_hidden($csrf, 'tiles_build', $camp); ?>
				<input type="hidden" name="layers_json" id="fcTileLayersJson" value="">

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

				<div class="fc-mapnote" id="fcTileSummary" style="margin-top:0.75em;"></div>
				<div class="fc-buttons">
					<button type="submit" class="button primary small" id="fcTileBuildBtn"<?php echo $tileStatus['status'] === 'building' ? ' disabled' : ''; ?>><?php
						echo $tileStatus['status'] === 'ready' ? 'Save and build the tiles again' : 'Save and build the offline tiles'; ?></button>
<?php $next = fc_next($steps, 'tiles'); ?>
					<button type="submit" class="button small" data-fc-next="<?php echo $next[0]; ?>">Next: <?php echo $next[1]; ?></button>
				</div>
				<div class="fc-small" style="margin-top:0.75em;">The sizes are estimates from typical tiles; the real size shows
					once the tiles are built. Zoom <?php echo FieldCampTiles::MAX_ZOOM; ?> is the deepest level offered for now.</div>
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
				'building' => $tileStatus['status'] === 'building',
				'campId' => (int)$camp->pkey,
			)); ?>;
			var form = document.getElementById('fcTileForm');
			var layerRows = Array.prototype.slice.call(form.querySelectorAll('.fc-tileline'));
			var zoomRows = Array.prototype.slice.call(form.querySelectorAll('.fc-zoomline[data-zoom]'));
			var btn = document.getElementById('fcTileBuildBtn');

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
				if(!layers.length) why = 'Select at least one map.';
				else if(z === null) why = 'Choose a zoom level.';
				document.getElementById('fcTileWhy').textContent = why;
				document.getElementById('fcTileSummary').textContent = summary;
				btn.disabled = cfg.building || why !== '';
				layerRows.forEach(function(r){ r.classList.toggle('fc-unit-off', !r.querySelector('.fc-tile-on').checked); });
			}

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

			// --- a build in progress --------------------------------------------
			if(cfg.building){
				var timer = setInterval(function(){
					fetch('/field_camp?id=' + cfg.campId + '&ajax=build', {credentials: 'same-origin'})
						.then(function(r){ return r.json(); })
						.then(function(s){
							if(s.status === 'building'){ document.getElementById('fcBuildMsg').textContent = s.message; return; }
							clearInterval(timer);
							window.location.href = '/field_camp?id=' + cfg.campId + '&step=tiles';
						})
						.catch(function(){ });
				}, 3000);
			}
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
							echo 'Being built now; the package has no tiles until the build is done';
						}else{
							echo 'None';
						}
						if(isset($steps['tiles'])) echo ' &nbsp; <a href="/field_camp?id=' . (int)$camp->pkey . '&amp;step=tiles">Offline maps</a>';
					?></td></tr>
				</table>
			</div>
			<div class="fc-buttons" style="margin-top:1em;">
				<a href="/field_camp_package?id=<?php echo (int)$camp->pkey; ?>" class="button primary small">Download the project package</a>
			</div>
			<div class="fc-small" style="margin-top:1em;">
				Every student loads this same file, as in past camps: send it to each device (AirDrop works), then in
				StraboField import the project from the zip and open it. The package always holds what is saved on the
				other steps at the moment you download it. If you change the rock units or maps after students have
				loaded it, download it again; devices that already hold the project keep the older version.
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
					<tr><td>Username</td><td>Project</td><td>Last upload</td><td>Datasets</td><td>Spots</td><td>Photos</td><td><?php echo $ownsCamp ? 'Your copy' : "Owner's copy"; ?></td></tr>
<?php 	foreach($roster['rows'] as $r){
			if(!$r['projects']){ ?>
					<tr>
						<td><?php echo htmlspecialchars($r['username']); ?><?php echo $r['active'] ? '' : ' <span class="fc-small">(deactivated)</span>'; ?></td>
						<td colspan="6" class="fc-small">Nothing uploaded yet</td>
					</tr>
<?php 		}
			foreach($r['projects'] as $i => $p){ ?>
					<tr>
						<td><?php echo $i === 0 ? htmlspecialchars($r['username']) . ($r['active'] ? '' : ' <span class="fc-small">(deactivated)</span>') : ''; ?></td>
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
			<div class="strabotable" style="margin-left:0px;">
				<table class="fc-table">
					<tr><td>Username</td><td>Created</td><td>Status</td><td>Projects uploaded</td><td>Copied to <?php echo $ownsCamp ? 'you' : 'the owner'; ?></td></tr>
<?php foreach($accounts as $a){ ?>
					<tr>
						<td><?php echo htmlspecialchars($a->username); ?></td>
						<td><?php echo htmlspecialchars(substr((string)$a->created_date, 0, 10)); ?></td>
						<td><?php echo FieldCamp::truthy($a->active) ? 'Active' : 'Deactivated'; ?></td>
						<td><?php echo (int)($projectCounts[(int)$a->user_pkey] ?? 0); ?></td>
						<td><?php echo (int)($copiedCounts[(int)$a->user_pkey] ?? 0); ?></td>
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
			<form method="post" action="/field_camp_action" style="margin-top:1.5em;">
				<?php echo fc_hidden($csrf, 'accounts_remove', $camp); ?>
				<div class="fc-field">
					<label for="fcConfirm">Remove the <?php echo (int)($liveCount - $activeCount); ?> deactivated account(s)</label>
					<div class="fc-hint" style="margin-bottom:0.5em;">
						Removed accounts are closed for good and their usernames cannot be used again.
<?php if($toCopy){ ?>
						<strong><?php echo count($toCopy); ?> of them still hold work that has not been copied.</strong>
<?php } ?>
						To confirm, type the name of this camp: <strong><?php echo htmlspecialchars($camp->camp_name); ?></strong>
					</div>
					<input type="text" name="confirm" id="fcConfirm" autocomplete="off">
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
