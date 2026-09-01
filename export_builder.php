<?php
/**
 * File: export_builder.php
 * Description: Export Builder (docs/ExportBuilder_Design.md §9.1). One page,
 *              three panels: SELECTION (own + collaborated StraboField
 *              projects with per-project dataset checkboxes), FILTERS (the
 *              StraboSearch criteria builder, locked to Field criteria, with
 *              the drawn-polygon area filter and a live "N spots match"
 *              count), OUTPUT (formats, layout, whole-project extras, email
 *              on completion, notes). Submit POSTs the recipe to
 *              exportjobs/api.php?action=create and lands on My Exports.
 *
 *              Doors: ?p=<project id>&owner=<pkey>[&d=<dataset id>] preselects
 *              (My Field Data); ?from=<job uuid> pre-fills everything from an
 *              earlier export's recipe (My Exports "Edit and re-run");
 *              POST search_dsl=<StraboSearch DSL JSON> (the results page's
 *              Export… button) preselects the caller's projects with
 *              matching spots and loads the Field-applicable filter rows.
 *
 *              Page shell per the site convention (mheader at global scope,
 *              wrapper style1 > container > header.major, dark palette,
 *              mfooter). The criteria builder is the StraboSearch one
 *              (catalog.js + builder.js + search.css, all class-scoped);
 *              the catalog is trimmed to Field-applicable criteria in page
 *              JS before init (no U4 owner / U8 subsystem / Micro / Exp /
 *              Image rows).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . '/exportjobs/lib/export_config.php';
require_once __DIR__ . '/exportjobs/lib/ExportJobService.php';
require_once __DIR__ . '/exportjobs/plugins/FieldExportPlugin.php';

$ebCfg = export_config();

// ---- project picker data: own projects + accepted collaborations --------
function eb_project_entry($pnode, $dlist, $owner, $access, $ownerName)
{
	$pv = $pnode->values();
	$name = isset($pv['desc_project_name']) && $pv['desc_project_name'] !== '' ? $pv['desc_project_name']
		: (isset($pv['projectname']) && $pv['projectname'] !== '' ? $pv['projectname'] : ('project ' . $pv['id']));
	$datasets = array(); $total = 0;
	foreach ((array)$dlist as $d) {
		$d = is_array($d) ? $d : (array)$d;
		if (!isset($d['d']) || $d['d'] === null) continue;
		$dv = is_object($d['d']) && method_exists($d['d'], 'values') ? $d['d']->values() : (array)$d['d'];
		if (!isset($dv['id'])) continue;
		$n = isset($d['count']) ? (int)$d['count'] : 0;
		$datasets[] = array('id' => (string)$dv['id'], 'name' => (isset($dv['name']) && $dv['name'] !== '') ? (string)$dv['name'] : ('dataset ' . $dv['id']), 'spots' => $n);
		$total += $n;
	}
	usort($datasets, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
	return array('id' => (string)$pv['id'], 'owner' => (int)$owner, 'name' => (string)$name, 'owner_name' => $ownerName,
		'access' => $access, 'spots' => $total, 'datasets' => $datasets,
		'uploaddate' => isset($pv['uploaddate']) ? (int)$pv['uploaddate'] : 0);
}

$ebProjects = array();
$ownRows = $neodb->get_results("MATCH (u:User {userpkey: $userpkey})-[:HAS_PROJECT]->(p:Project) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot) WITH p, d, count(s) AS count WITH p, collect({d: d, count: count}) AS d RETURN p, d ORDER BY p.uploaddate DESC");
foreach ((array)$ownRows as $r) {
	$ebProjects[] = eb_project_entry($r->get('p'), $r->get('d'), $userpkey, 'owner', 'you');
}
$collabRows = $strabo->getCollaborationProjects();
if (is_array($collabRows)) {
	foreach ($collabRows as $crow) {
		$c = $crow->collaboration;
		$o = $db->get_row_prepared("SELECT firstname, lastname, email FROM users WHERE pkey = $1", array((int)$c->project_owner_user_pkey));
		$oname = $o ? trim($o->firstname . ' ' . $o->lastname) : ('user ' . $c->project_owner_user_pkey);
		if ($oname === '' && $o) $oname = $o->email;
		$ebProjects[] = eb_project_entry($crow->project->get('p'), $crow->project->get('d'), (int)$c->project_owner_user_pkey, 'collaborator', $oname);
	}
}

// ---- doors ---------------------------------------------------------------
$ebPreselect = null;
if (isset($_GET['p']) && preg_match('/^[0-9]{1,20}$/', (string)$_GET['p'])) {
	$ebPreselect = array('project_id' => (string)$_GET['p'], 'owner' => isset($_GET['owner']) ? (int)$_GET['owner'] : $userpkey,
		'dataset_id' => (isset($_GET['d']) && preg_match('/^[0-9]{1,20}$/', (string)$_GET['d'])) ? (string)$_GET['d'] : null);
}
/**
 * StraboSearch door (M6): the results page POSTs its last-run DSL as
 * `search_dsl`. Keep the Field-applicable criteria rows (Universal minus
 * owner/subsystem, plus Field), and preselect every project in the caller's
 * picker that has at least one spot matching them (one GROUP BY over the
 * search index, same ACL as the export itself). Public projects of other
 * users that were in the result set cannot be exported and are not offered.
 * Returns {initial, note} or null when the payload is unusable.
 */
function eb_from_search($db, $userpkey, array $projects, $dsl)
{
	if (!is_array($dsl)) return null;
	require_once __DIR__ . '/searchdb/services/SearchQueryBuilder.php';
	$note = array();
	$fieldExcluded = isset($dsl['subsystems']) && is_array($dsl['subsystems']) && $dsl['subsystems'] && !in_array('field', $dsl['subsystems'], true);

	$kept = array(); $dropped = 0;
	foreach ((isset($dsl['criteria']) && is_array($dsl['criteria'])) ? $dsl['criteria'] : array() as $row) {
		$id = (is_array($row) && isset($row['id'])) ? strtoupper((string)$row['id']) : '';
		if (preg_match('/^(U|F)[0-9]+$/', $id) && $id !== 'U4' && $id !== 'U8') $kept[] = $row; else $dropped++;
	}
	$validated = null;
	if ($kept) {
		try {
			$qb = new SearchQueryBuilder($db, $userpkey);
			$validated = $qb->validate(array('subsystems' => array('field'), 'pathway' => 'projects', 'criteria' => $kept));
		} catch (SearchDslError $e) {
			$note[] = 'The search filters could not be carried over (' . $e->getMessage() . ').';
			$kept = array(); $validated = null;
		}
	}

	$candidates = array();
	foreach ($projects as $p) if ($p['spots'] > 0) $candidates[] = array('project_id' => $p['id'], 'owner' => $p['owner']);
	$matched = array();
	if ($fieldExcluded) {
		$note[] = 'Your search left out StraboField, so no projects were preselected.';
	} elseif ($validated !== null && count(array_filter($validated['criteria'], function ($c) { return $c['id'] !== 'U2'; })) > 0) {
		$hits = $candidates ? $qb->runItemProjectCountsQuery($validated, $candidates) : array();
		foreach ($candidates as $c) if (isset($hits[$c['project_id'] . '|' . $c['owner']])) $matched[] = $c;
	} else {
		$matched = $candidates;      // no non-spatial filter: every project with spots qualifies (an area filter is applied at build time)
	}
	$total = count($matched);
	if ($total > 50) { $matched = array_slice($matched, 0, 50); $note[] = "Only the first 50 of $total matching projects were preselected (the per-export limit)."; }

	$scope = array('projects' => array(), 'datasets' => array());
	$names = array(); $byKey = array();
	foreach ($projects as $p) $byKey[$p['id'] . '|' . $p['owner']] = $p['name'];
	foreach ($matched as $m) {
		$scope['projects'][] = array('id' => $m['project_id'], 'owner' => $m['owner']);
		$names[] = isset($byKey[$m['project_id'] . '|' . $m['owner']]) ? $byKey[$m['project_id'] . '|' . $m['owner']] : $m['project_id'];
	}
	if (!$fieldExcluded) {
		$shown = implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? ' and ' . (count($names) - 6) . ' more' : '');
		$lead = $total === 0
			? 'None of your StraboField projects have spots matching these filters. Pick projects below or loosen the filters.'
			: ($total . ' of your StraboField project' . ($total === 1 ? ' has' : 's have') . ' spots matching these filters and ' . ($total === 1 ? 'is' : 'are') . ' preselected: ' . $shown . '.');
		array_unshift($note, $lead);
	}
	if ($dropped) $note[] = $dropped . ' filter row' . ($dropped === 1 ? '' : 's') . ' that cannot apply to StraboField exports (Micro, Experimental, Image, owner or subsystem rows) ' . ($dropped === 1 ? 'was' : 'were') . ' left out.';

	$initial = array('scope' => $scope, 'criteria' => $kept, 'children' => 'matched_parents', 'formats' => array('geojson'),
		'layout' => 'merged', 'extras' => array(), 'sample_list_csv' => false, 'notes' => '');
	return array('initial' => $initial, 'note' => implode(' ', $note));
}

$ebInitial = null; $ebFromSummary = null; $ebFromSearch = null;
if (isset($_GET['from']) && UUID::is_valid((string)$_GET['from'])) {
	$ebSvc = new ExportJobService($db, $ebCfg);
	$src = $ebSvc->get((string)$_GET['from'], $userpkey);      // owner-scoped: someone else's uuid is simply ignored
	if ($src && is_array($src['recipe'])) { $ebInitial = $src['recipe']; $ebFromSummary = $src['recipe_summary']; }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_dsl'])) {
	$door = eb_from_search($db, $userpkey, $ebProjects, json_decode((string)$_POST['search_dsl'], true));
	if ($door) { $ebInitial = $door['initial']; $ebFromSearch = $door['note']; }
}

$ebFormats = array(
	array('key' => 'shapefile',   'label' => 'Shapefile',          'hint' => 'points, lines and polygons .shp set (GIS)'),
	array('key' => 'geojson',     'label' => 'GeoJSON',            'hint' => 'one .geojson file with every property'),
	array('key' => 'gpkg',        'label' => 'GeoPackage',         'hint' => 'one .gpkg (QGIS, ArcGIS)'),
	array('key' => 'kmz',         'label' => 'KMZ',                'hint' => 'Google Earth, icons per spot'),
	array('key' => 'xls',         'label' => 'Excel workbook',     'hint' => 'one sheet per data type'),
	array('key' => 'stereonet',   'label' => 'Stereonet file',     'hint' => 'orientation measurements only'),
	array('key' => 'fieldbook',   'label' => 'Field book PDF',     'hint' => 'slow for large selections'),
	array('key' => 'images',      'label' => 'Images',             'hint' => 'original photos and sketches (largest)'),
	array('key' => 'sample_list', 'label' => 'Sample list',        'hint' => 'StraboSamples workbook for the samples on these spots, with Micro / Experimental links'),
);
$ebExtras = array(
	array('key' => 'project_json',   'label' => 'Project JSON',            'hint' => 'the whole project as StraboSpot JSON'),
	array('key' => 'geologic_units', 'label' => 'Geologic units workbook', 'hint' => 'the project\'s unit descriptions'),
);

function eb_asset($path) {
	$mtime = @filemtime(__DIR__ . '/' . ltrim($path, '/'));
	return htmlspecialchars($path . ($mtime ? '?v=' . $mtime : ''));
}

include("includes/mheader.php");
?>

<link rel="stylesheet" href="/assets/js/leaflet/leaflet.css" />
<link rel="stylesheet" href="<?php echo eb_asset('/strabosearch/css/search.css'); ?>" />
<style>
/* search.css is loaded for the criteria builder, but it also locks the
   page body (overflow: hidden at every breakpoint) because /search is a
   full-viewport app frame whose panes scroll instead of the page. This
   page is an ordinary scrolling page with a footer, so undo that lock
   here (same specificity, later in source order wins). */
body { overflow: visible; }
.eb-intro { color: rgba(255,255,255,0.7); max-width: 60em; margin: 0 auto 1.5em auto; text-align: center; }
.eb-panel { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.12); border-radius: 6px; padding: 1.25em 1.5em; margin-bottom: 1.5em; }
.eb-panel h3 { margin: 0 0 0.25em 0; font-size: 1.15em; }
.eb-panel h3 .eb-step { display: inline-block; background: #e44c65; color: #fff; border-radius: 50%; width: 1.6em; height: 1.6em; line-height: 1.6em; text-align: center; font-size: 0.85em; margin-right: 0.5em; }
.eb-panel .eb-sub { color: rgba(255,255,255,0.6); font-size: 0.9em; margin-bottom: 1em; }
.eb-tools { display: flex; flex-wrap: wrap; gap: 0.75em; align-items: center; margin-bottom: 0.75em; }
.eb-tools input[type="text"] { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; color: #fff; padding: 0.5em 0.75em; font-size: 0.95em; flex: 1 1 14em; min-width: 10em; }
.eb-tools a { font-size: 0.85em; color: rgba(255,255,255,0.7); }
.eb-tools a:hover { color: #f06880; }
.eb-projects { max-height: 26em; overflow-y: auto; border: 1px solid rgba(255,255,255,0.1); border-radius: 4px; }
.eb-proj { border-bottom: 1px solid rgba(255,255,255,0.08); padding: 0.55em 0.75em; }
.eb-proj:last-child { border-bottom: 0; }
.eb-proj-row { display: flex; align-items: center; gap: 0.5em; }
.eb-proj-row label { flex: 1 1 auto; font-weight: 600; cursor: pointer; }
.eb-proj-row label small { font-weight: normal; color: rgba(255,255,255,0.55); margin-left: 0.5em; }
.eb-proj-row .eb-access { font-size: 0.72em; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.45); border: 1px solid rgba(255,255,255,0.2); border-radius: 3px; padding: 0.1em 0.4em; }
.eb-proj-row .eb-toggle { background: none; border: 1px solid rgba(255,255,255,0.25); color: rgba(255,255,255,0.7); border-radius: 3px; font-size: 0.75em; padding: 0.15em 0.5em; cursor: pointer; line-height: 1.4; }
.eb-proj-row .eb-toggle:hover { color: #fff; border-color: #f06880; }
.eb-datasets { display: none; margin: 0.4em 0 0.2em 2.2em; }
.eb-proj.eb-open .eb-datasets { display: block; }
.eb-ds { display: flex; align-items: center; gap: 0.5em; padding: 0.15em 0; }
.eb-ds label { font-size: 0.92em; cursor: pointer; }
.eb-ds label small { color: rgba(255,255,255,0.5); margin-left: 0.4em; }
.eb-proj input[type="checkbox"] + label { padding-left: 2.2em; margin: 0; }
.eb-proj input[type="checkbox"] + label:before { top: 0; }
.eb-empty { padding: 1.5em; text-align: center; color: rgba(255,255,255,0.6); }
.eb-hidden { display: none !important; }
.eb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(17em, 1fr)); gap: 0.25em 1.5em; }
.eb-opt { display: flex; flex-direction: column; padding: 0.25em 0; }
.eb-opt label { cursor: pointer; margin: 0; }
.eb-opt small { color: rgba(255,255,255,0.5); font-size: 0.8em; margin-left: 2.35em; margin-top: -0.2em; }
.eb-opt input[type="checkbox"] + label, .eb-opt input[type="radio"] + label { padding-left: 2.2em; }
.eb-opt input[type="checkbox"] + label:before, .eb-opt input[type="radio"] + label:before { top: 0.1em; }
.eb-block { margin-top: 1.1em; padding-top: 0.9em; border-top: 1px solid rgba(255,255,255,0.1); }
.eb-block h4 { font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(255,255,255,0.6); margin: 0 0 0.5em 0; }
.eb-block textarea { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; color: #fff; padding: 0.5em 0.75em; width: 100%; min-height: 4em; font-size: 0.95em; }
.eb-note { color: rgba(255,255,255,0.55); font-size: 0.85em; margin-top: 0.4em; }
.eb-bar { position: sticky; bottom: 0; background: #272833; border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; padding: 0.9em 1.25em; display: flex; flex-wrap: wrap; gap: 0.75em 1.5em; align-items: center; justify-content: space-between; box-shadow: 0 -6px 18px rgba(0,0,0,0.35); margin-bottom: 2em; }
.eb-count { font-size: 1.05em; }
.eb-count strong { color: #fff; }
.eb-count .eb-approx { color: rgba(255,255,255,0.55); font-size: 0.85em; margin-left: 0.5em; }
.eb-count.eb-warn strong { color: #f0a050; }
.eb-count.eb-err { color: #f06880; }
.eb-bar .button.primary[disabled], .eb-bar .button.primary.eb-disabled { opacity: 0.45; pointer-events: none; }
.eb-msg { color: #f06880; margin-top: 0.5em; font-size: 0.9em; }
.eb-from { background: rgba(228,76,101,0.12); border: 1px solid rgba(228,76,101,0.4); border-radius: 4px; padding: 0.6em 1em; margin-bottom: 1.25em; font-size: 0.92em; }
.eb-panel .ss-criteria { margin-top: 0.5em; }
.eb-drift { color: rgba(255,255,255,0.5); font-size: 0.82em; margin-top: 0.6em; }
@media (max-width: 736px) { .eb-panel { padding: 1em; } .eb-bar { position: static; } }
</style>

<div id="main" class="wrapper style1">
	<div class="container">
		<header class="major">
			<h2>Export Builder</h2>
		</header>
		<p class="eb-intro">Pick StraboField projects or datasets, narrow them with filters if you like, choose the output formats, and build a downloadable package in the background. You will find it on <a href="/my_exports">My Exports</a> when it is ready.</p>

<?php if ($ebFromSearch !== null) { ?>
		<div class="eb-from" id="eb-from"><strong>From StraboSearch.</strong> <?php echo htmlspecialchars($ebFromSearch); ?></div>
<?php } elseif ($ebInitial) { ?>
		<div class="eb-from" id="eb-from">Editing a copy of an earlier export<?php echo $ebFromSummary ? ': <strong>' . htmlspecialchars($ebFromSummary) . '</strong>' : ''; ?>. Adjust anything below and build again.</div>
<?php } ?>

		<!-- 1. SELECTION -->
		<section class="eb-panel" id="eb-selection">
			<h3><span class="eb-step">1</span>Selection</h3>
			<div class="eb-sub">Your own projects and the ones you collaborate on. Tick a project for all of its datasets, or expand it and pick datasets.</div>
			<div class="eb-tools">
				<input type="text" id="eb-proj-search" placeholder="Find a project…" autocomplete="off" aria-label="Find a project">
				<a href="javascript:void(0);" id="eb-select-none">Clear selection</a>
			</div>
			<div class="eb-projects" id="eb-projects"></div>
		</section>

		<!-- 2. FILTERS -->
		<section class="eb-panel" id="eb-filters">
			<h3><span class="eb-step">2</span>Filters <small style="font-weight:normal;color:rgba(255,255,255,0.55);">(optional)</small></h3>
			<div class="eb-sub">Keep only the spots that match. Geographic Location draws an area on a map; spots whose geometry touches it are kept. Nested child spots of a matching spot come along.</div>
			<div id="criteriaBuilder" class="ss-criteria" aria-label="Export filters"></div>
			<div class="eb-drift" id="eb-drift"></div>
		</section>

		<!-- 3. OUTPUT -->
		<section class="eb-panel" id="eb-output">
			<h3><span class="eb-step">3</span>Output</h3>
			<div class="eb-sub">Everything you tick goes into one zip.</div>
			<div class="eb-grid" id="eb-formats">
<?php foreach ($ebFormats as $f) { ?>
				<div class="eb-opt">
					<input type="checkbox" class="eb-fmt" id="eb-fmt-<?php echo $f['key']; ?>" value="<?php echo $f['key']; ?>">
					<label for="eb-fmt-<?php echo $f['key']; ?>"><?php echo htmlspecialchars($f['label']); ?></label>
					<small><?php echo htmlspecialchars($f['hint']); ?></small>
				</div>
<?php } ?>
			</div>
			<div class="eb-opt eb-hidden" id="eb-csv-wrap" style="margin-left:1.5em;">
				<input type="checkbox" id="eb-sample-csv">
				<label for="eb-sample-csv">Also write the sample list as CSV</label>
			</div>

			<div class="eb-block eb-hidden" id="eb-layout-block">
				<h4>Layout</h4>
				<div class="eb-grid">
					<div class="eb-opt"><input type="radio" name="eb-layout" id="eb-layout-merged" value="merged" checked><label for="eb-layout-merged">Merged</label><small>one output set; every feature carries project and dataset columns</small></div>
					<div class="eb-opt"><input type="radio" name="eb-layout" id="eb-layout-project" value="split_project"><label for="eb-layout-project">Split by project</label><small>projects/&lt;name&gt;/…</small></div>
					<div class="eb-opt"><input type="radio" name="eb-layout" id="eb-layout-dataset" value="split_dataset"><label for="eb-layout-dataset">Split by dataset</label><small>projects/&lt;name&gt;/datasets/&lt;name&gt;/…</small></div>
				</div>
			</div>

			<div class="eb-block eb-hidden" id="eb-extras-block">
				<h4>Whole-project extras</h4>
				<div class="eb-grid">
<?php foreach ($ebExtras as $x) { ?>
					<div class="eb-opt">
						<input type="checkbox" class="eb-extra" id="eb-extra-<?php echo $x['key']; ?>" value="<?php echo $x['key']; ?>">
						<label for="eb-extra-<?php echo $x['key']; ?>"><?php echo htmlspecialchars($x['label']); ?></label>
						<small><?php echo htmlspecialchars($x['hint']); ?></small>
					</div>
<?php } ?>
				</div>
				<div class="eb-note">Added for every project selected as a whole (not for partial dataset picks).</div>
			</div>

			<div class="eb-block">
				<h4>Options</h4>
				<div class="eb-grid">
					<div class="eb-opt"><input type="checkbox" id="eb-children" checked><label for="eb-children">Include nested child spots</label><small>children of spots that match the area filter</small></div>
					<div class="eb-opt"><input type="checkbox" id="eb-email"><label for="eb-email">Email me when it is ready</label><small>a link to My Exports, no attachment</small></div>
				</div>
				<div style="margin-top:0.75em;">
					<textarea id="eb-notes" maxlength="500" placeholder="Notes for the README (optional)"></textarea>
				</div>
			</div>
		</section>

		<div class="eb-bar">
			<div>
				<div class="eb-count" id="eb-count">Select a project to begin.</div>
				<div class="eb-msg" id="eb-msg"></div>
			</div>
			<a href="javascript:void(0);" id="eb-build" class="button primary eb-disabled">Build export</a>
		</div>
	</div>
</div>

<script>
window.STRABO_SEARCH = { api: '/strabosearch/api.php' };   // vocab typeaheads inside the criteria builder
window.EXPORT_BUILDER = <?php echo json_encode(array(
	'api'       => '/exportjobs/api.php',
	'projects'  => $ebProjects,
	'preselect' => $ebPreselect,
	'initial'   => $ebInitial,
	'maxItems'  => (int)$ebCfg['max_items'],
	'formats'   => array_map(function ($f) { return $f['key']; }, $ebFormats),
), JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="/assets/js/leaflet/leaflet.js"></script>
<script src="<?php echo eb_asset('/strabosearch/js/catalog.js'); ?>"></script>
<script src="<?php echo eb_asset('/strabosearch/js/builder.js'); ?>"></script>
<script src="<?php echo eb_asset('/exportjobs/js/export_builder.js'); ?>"></script>

<?php
include("includes/mfooter.php");
