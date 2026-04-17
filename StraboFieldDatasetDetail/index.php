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

$dataset_id = isset($_GET['dataset_id']) ? (int)$_GET['dataset_id'] : 0;

$error_message = null;
$owner_pkey = null;
$project_id = null;
$project_name = '';
$dataset_name = '';
$is_public = false;

if(!$dataset_id){
	$error_message = 'No dataset id provided. Use ?dataset_id=XXXXXXXX.';
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
<link rel="stylesheet" href="/StraboFieldDatasetDetail/css/detail.css">

<div id="dataset-detail-root">
	<div id="dataset-detail-header">
		<div class="ddh-title">
			<h2>
				<span class="ddh-dataset"><?= htmlspecialchars($dataset_name ?: 'Untitled dataset') ?></span>
				<span class="ddh-sep">&mdash;</span>
				<span class="ddh-project"><?= htmlspecialchars($project_name ?: 'Untitled project') ?></span>
			</h2>
			<div class="ddh-meta">
				Dataset <?= (int)$dataset_id ?>
				<span class="ddh-pub <?= $is_public ? 'is-public' : 'is-private' ?>">
					<?= $is_public ? 'public' : 'private' ?>
				</span>
			</div>
		</div>
		<div class="ddh-actions">
			<!-- Phase 6 will place the Download dropdown here -->
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
	is_public: <?= $is_public ? 'true' : 'false' ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/ol@10.4.0/dist/ol.js"></script>
<script src="https://unpkg.com/ol-layerswitcher@4.1.2/dist/ol-layerswitcher.js"></script>
<script src="/StraboFieldDatasetDetail/js/basemaps.js"></script>
<script src="/StraboFieldDatasetDetail/js/symbology.js"></script>
<script src="/StraboFieldDatasetDetail/js/sidebar.js"></script>
<script src="/StraboFieldDatasetDetail/js/spots.js"></script>
<script src="/StraboFieldDatasetDetail/js/image_basemap.js"></script>
<script src="/StraboFieldDatasetDetail/js/strat_section.js"></script>
<script src="/StraboFieldDatasetDetail/js/detail.js"></script>

<?php
include("includes/mfooter.php");
?>
