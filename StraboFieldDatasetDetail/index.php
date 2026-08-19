<?php
/**
 * File: StraboFieldDatasetDetail/index.php
 * Description: StraboField dataset landing page. Single-dataset map viewer
 *              with metadata sidebar, image basemap / strat section modes,
 *              and downloads. Accessible to anyone regardless of project
 *              public/private status.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

chdir(dirname(__FILE__) . '/..');
include("includes/mheader.php");

// mtime cache-buster: statics ship no Cache-Control headers, so browsers
// heuristic-cache CSS/JS and a plain reload serves stale assets after a
// deploy. ?v=<mtime> makes every edit a new URL (same pattern as
// /strabosearch/).
function dsd_asset($path) {
	$mtime = @filemtime(dirname(__FILE__) . '/../' . ltrim($path, '/'));
	return htmlspecialchars($path . ($mtime ? '?v=' . $mtime : ''));
}

$dataset_id = isset($_GET['dataset_id']) ? (int)$_GET['dataset_id'] : 0;
$sample_id  = isset($_GET['sample_id'])  ? (int)$_GET['sample_id']  : 0;

$error_message = null;
$owner_pkey = null;
$project_id = null;
$project_name = '';
$dataset_name = '';
$is_public = false;
$highlight_spot_id = null;

// ?sample_id= entry (replaces the legacy /spotdetails/?s= page): resolve the
// sample's internal id to its containing dataset + spot, then load the page
// exactly as if that dataset_id had been requested, with the spot highlighted.
// An explicit ?dataset_id= wins if both are given.
if(!$dataset_id && $sample_id){
	$sample_rows = $neodb->get_results("
		MATCH (d:Dataset)-[:HAS_SPOT]->(s:Spot)-[:HAS_SAMPLE]->(smp:Sample)
		WHERE smp.id = $sample_id
		RETURN d.id AS dataset_id, s.id AS spot_id
		LIMIT 1
	");
	if(!$sample_rows || count($sample_rows) === 0){
		$error_message = 'Sample not found.';
	}else{
		$dataset_id        = (int)$sample_rows[0]->value('dataset_id');
		$highlight_spot_id = $sample_rows[0]->value('spot_id');
	}
}

if($error_message){
	// Sample lookup already failed — fall through to the error page.
}elseif(!$dataset_id){
	$error_message = 'No dataset id provided. Use ?dataset_id=XXXXXXXX or ?sample_id=XXXXXXXX.';
}else{
	$records = $neodb->get_results("
		MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset)
		WHERE d.id = $dataset_id
		RETURN p.userpkey AS owner_pkey,
		       p.id AS project_id,
		       coalesce(p.desc_project_name, p.name, '') AS project_name,
		       coalesce(d.name, '') AS dataset_name,
		       p.public AS is_public
		LIMIT 1
	");

	if(!$records || count($records) === 0){
		$error_message = 'Dataset not found.';
	}else{
		$row = $records[0];
		$owner_pkey   = (int)$row->value('owner_pkey');
		$project_id   = $row->value('project_id');
		$project_name = (string)$row->value('project_name');
		$dataset_name = (string)$row->value('dataset_name');
		$public_val   = $row->value('is_public');
		$is_public    = ($public_val === true || $public_val === 1 || $public_val === '1' || $public_val === 'true');
	}
}

if($error_message):
?>
<div id="main" class="wrapper style1">
	<div class="container">
		<header class="major"><h2>StraboField Dataset</h2></header>
		<p style="font-size: 1.2em;"><?= htmlspecialchars($error_message) ?></p>
		<p><a href="/">Return to home</a></p>
	</div>
</div>
<?php
include("includes/mfooter.php");
exit;
endif;
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@10.4.0/ol.css">
<link rel="stylesheet" href="https://unpkg.com/ol-layerswitcher@4.1.2/dist/ol-layerswitcher.css">
<!-- featherlight.js ships globally via mheader.php, but its CSS does not -->
<link rel="stylesheet" href="/assets/js/featherlight/featherlight.css">
<link rel="stylesheet" href="<?= dsd_asset('/StraboFieldDatasetDetail/css/detail.css') ?>">

<div id="dataset-detail-root">
	<div id="dataset-detail-header">
		<div class="ddh-title">
			<h2>
				<span class="ddh-prefix">Dataset Details:</span>
				<span class="ddh-dataset"><?= htmlspecialchars($dataset_name ?: 'Untitled dataset') ?></span>
				<span class="ddh-sep">&ndash;</span>
				<span class="ddh-project"><?= htmlspecialchars($project_name ?: 'Untitled project') ?></span>
			</h2>
		</div>
		<div class="ddh-actions">
			<select class="myDataSelect ddh-download" id="ddh-download-select" aria-label="Download dataset">
				<option value="" style="display:none;">Download</option>
				<option value="shapefile">Shapefile</option>
				<option value="kml">KMZ</option>
				<option value="xls">XLS</option>
				<option value="stereonet">Stereonet Mobile</option>
				<option value="fieldbook">Field Book</option>
				<option value="strat_sections">Strat Section(s)</option>
				<option value="download_images">Download Photos</option>
				<option value="sample_list">Sample List</option>
				<?php if ((int)$_SESSION['userpkey'] === 3): ?>
				<option value="dev_sample_list">Dev Sample List</option>
				<?php endif; ?>
				<option value="gpkg">GeoPackage</option>
				<option value="geojson">GeoJSON</option>
				<option value="gems">USGS GeMS</option>
				<option value="image_basemaps">Image Basemaps</option>
			</select>
		</div>
	</div>
	<div id="dataset-detail-body">
		<div id="map" aria-label="Dataset map"></div>
		<aside id="dataset-sidebar" class="dataset-sidebar" aria-hidden="true">
			<!-- Phase 3 will render sidebar content here -->
		</aside>
	</div>
</div>

<script>
window.DATASET_DETAIL_CONFIG = {
	dataset_id: <?= (int)$dataset_id ?>,
	project_id: <?= json_encode($project_id) ?>,
	owner_pkey: <?= (int)$owner_pkey ?>,
	dataset_name: <?= json_encode($dataset_name) ?>,
	project_name: <?= json_encode($project_name) ?>,
	is_public: <?= $is_public ? 'true' : 'false' ?>,
	highlight_spot_id: <?= json_encode($highlight_spot_id) ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/ol@10.4.0/dist/ol.js"></script>
<script src="https://unpkg.com/ol-layerswitcher@4.1.2/dist/ol-layerswitcher.js"></script>
<script src="<?= dsd_asset('/StraboFieldDatasetDetail/js/basemaps.js') ?>"></script>
<script src="<?= dsd_asset('/StraboFieldDatasetDetail/js/symbology.js') ?>"></script>
<script src="<?= dsd_asset('/StraboFieldDatasetDetail/js/sidebar.js') ?>"></script>
<script src="<?= dsd_asset('/StraboFieldDatasetDetail/js/spots.js') ?>"></script>
<script src="<?= dsd_asset('/StraboFieldDatasetDetail/js/image_basemap.js') ?>"></script>
<script src="<?= dsd_asset('/StraboFieldDatasetDetail/js/strat_section.js') ?>"></script>
<script src="<?= dsd_asset('/StraboFieldDatasetDetail/js/downloads.js') ?>"></script>
<script src="<?= dsd_asset('/StraboFieldDatasetDetail/js/detail.js') ?>"></script>

<?php
include("includes/mfooter.php");
?>
