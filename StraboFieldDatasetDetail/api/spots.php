<?php
/**
 * File: StraboFieldDatasetDetail/api/spots.php
 * Description: Public JSON endpoint for the dataset landing page. Returns a
 *              GeoJSON FeatureCollection of all spots in a single dataset,
 *              regardless of project public/private status. No auth required.
 *              Each feature carries properties.tags — the project-level
 *              tags (incl. geologic units) joined to that spot, membership
 *              arrays stripped.
 *
 *              Response shape:
 *                {
 *                  type: "FeatureCollection",
 *                  features: [ ...GeoJSON features... ],
 *                  envelope: [west, south, east, north] | null,
 *                  image_basemap_ids: [ ... ],
 *                  strat_section_ids: [ ... ],
 *                  dataset: { id, name, project_id, project_name, owner_pkey, is_public }
 *                }
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

ob_start('ob_gzhandler');
header('Content-Type: application/json');

chdir(dirname(__FILE__) . '/../..');
include_once 'includes/config.inc.php';
include 'db.php';
include 'neodb.php';
include 'db/strabospotclass.php';
include_once 'includes/geophp/geoPHP.inc';
include_once 'includes/UUID.php';

$geoPHP = new geoPHP;

$dataset_id = isset($_GET['dataset_id']) ? (int)$_GET['dataset_id'] : 0;

if (!$dataset_id) {
	http_response_code(400);
	echo json_encode(['error' => 'dataset_id is required']);
	exit;
}

// Resolve dataset → project → owner. Used as the $userpkey for StraboSpot
// helpers (which otherwise default to 99999 for anonymous).
// json_tags rides along because tags (incl. geologic units / rock type)
// live ONLY in this project-level blob — Spot nodes carry no tag data and
// the IS_TAGGED edges are dead (writer disabled 2025-07-08). The 1MB
// substring bound matches the search extractors (worst prod blob ~200KB).
$meta_rows = $neodb->get_results("
	MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset)
	WHERE d.id = $dataset_id
	RETURN p.userpkey AS owner_pkey,
	       p.id AS project_id,
	       coalesce(p.desc_project_name, p.name, '') AS project_name,
	       coalesce(d.name, '') AS dataset_name,
	       p.public AS is_public,
	       substring(toString(p.json_tags), 0, 1000000) AS json_tags
	LIMIT 1
");

if (!$meta_rows || count($meta_rows) === 0) {
	http_response_code(404);
	echo json_encode(['error' => 'Dataset not found']);
	exit;
}

$meta_row    = $meta_rows[0];
$owner_pkey  = (int)$meta_row->value('owner_pkey');
$project_id  = $meta_row->value('project_id');
$project_name = (string)$meta_row->value('project_name');
$dataset_name = (string)$meta_row->value('dataset_name');
$public_val  = $meta_row->value('is_public');
$is_public   = ($public_val === true || $public_val === 1 || $public_val === '1' || $public_val === 'true');

$tag_map = spotTagMapFromJsonTags($meta_row->value('json_tags'));

$strabo = new StraboSpot($neodb, $owner_pkey, $db);
$strabo->setuuid(new UUID());

// Pull all spots for the dataset plus their images. No bbox filter — we're
// loading a single dataset worth of spots, which is bounded.
$spot_rows = $neodb->get_results("
	MATCH (d:Dataset)-[:HAS_SPOT]->(s:Spot)
	WHERE d.id = $dataset_id
	OPTIONAL MATCH (s)-[:HAS_IMAGE]->(i:Image)
	RETURN s, collect(i) AS images
");

$features          = array();
$image_basemap_ids = array();
$strat_section_ids = array();
$west = INF;  $south = INF;
$east = -INF; $north = -INF;

if ($spot_rows) {
	foreach ($spot_rows as $row) {
		$spot_node = $row->value('s');
		if (!$spot_node) continue;
		$spotvals = (object)$spot_node->values();

		$image_nodes = $row->value('images');
		$imagestuff  = array();
		if ($image_nodes) {
			foreach ($image_nodes as $img) {
				if (!$img) continue;
				$imagestuff[] = (object)$img->values();
			}
		}

		if (!empty($spotvals->image_basemap)) {
			$image_basemap_ids[] = $spotvals->image_basemap;
		}
		if (!empty($spotvals->strat_section_id)) {
			$strat_section_ids[] = $spotvals->strat_section_id;
		}

		$wkt = isset($spotvals->origwkt) && $spotvals->origwkt !== '' ? $spotvals->origwkt : $spotvals->wkt;
		if (empty($wkt)) continue;
		if (isset($spotvals->geometrytype) && $spotvals->geometrytype === 'wwwPoint') continue;

		$feature = $strabo->singleSpotJSONFromFeatureData($spotvals, $imagestuff);
		if (!$feature || !isset($feature['geometry'])) continue;

		$feature['properties']['datasetid'] = $owner_pkey . '-' . $dataset_id;

		$spot_key = isset($spotvals->id) ? (string)$spotvals->id : '';
		if ($spot_key !== '' && isset($tag_map[$spot_key])) {
			$feature['properties']['tags'] = $tag_map[$spot_key];
		}

		// Only geographic spots contribute to the map envelope. Spots that live
		// on an image basemap or strat section are in pixel space.
		if (empty($spotvals->image_basemap) && empty($spotvals->strat_section_id)) {
			extendEnvelope($feature['geometry'], $west, $south, $east, $north);
		}

		$features[] = $feature;
	}
}

/**
 * Build a spot_id → [tag objects] map from the project's json_tags blob.
 * Membership is each tag's `spots` id array plus the keys of its
 * `features` map (sub-spot tagging — the tag still belongs on that spot's
 * card). The bulky membership fields are stripped from the tag objects
 * before they ride each feature's properties. Tolerates null / empty /
 * malformed input.
 */
function spotTagMapFromJsonTags($raw) {
	if ($raw === null || $raw === '' || $raw === false) return array();
	$tags = json_decode((string)$raw);
	if (!is_array($tags)) return array();
	$map = array();
	foreach ($tags as $t) {
		if (!is_object($t)) continue;
		$stripped = clone $t;
		unset($stripped->spots);
		unset($stripped->features);
		$spot_ids = array();
		if (isset($t->spots) && is_array($t->spots)) {
			foreach ($t->spots as $sid) {
				if ($sid === null || $sid === '') continue;
				$spot_ids[(string)$sid] = true;
			}
		}
		if (isset($t->features) && is_object($t->features)) {
			foreach ($t->features as $sid => $feature_ids) {
				if ((string)$sid === '') continue;
				$spot_ids[(string)$sid] = true;
			}
		}
		foreach (array_keys($spot_ids) as $sid) {
			$map[$sid][] = $stripped;
		}
	}
	return $map;
}

function extendEnvelope($geometry, &$west, &$south, &$east, &$north) {
	if (!$geometry || !isset($geometry->type)) return;
	$coords = isset($geometry->coordinates) ? $geometry->coordinates : null;
	if (!$coords) return;

	switch ($geometry->type) {
		case 'Point':
			applyCoord($coords, $west, $south, $east, $north);
			break;
		case 'MultiPoint':
		case 'LineString':
			foreach ($coords as $c) applyCoord($c, $west, $south, $east, $north);
			break;
		case 'MultiLineString':
		case 'Polygon':
			foreach ($coords as $ring) {
				foreach ($ring as $c) applyCoord($c, $west, $south, $east, $north);
			}
			break;
		case 'MultiPolygon':
			foreach ($coords as $poly) {
				foreach ($poly as $ring) {
					foreach ($ring as $c) applyCoord($c, $west, $south, $east, $north);
				}
			}
			break;
	}
}

function applyCoord($c, &$west, &$south, &$east, &$north) {
	if (!is_array($c) || count($c) < 2) return;
	$lon = $c[0]; $lat = $c[1];
	if ($lon < $west)  $west  = $lon;
	if ($lon > $east)  $east  = $lon;
	if ($lat < $south) $south = $lat;
	if ($lat > $north) $north = $lat;
}

$envelope = null;
if (is_finite($west) && is_finite($east) && is_finite($south) && is_finite($north)) {
	$envelope = array($west, $south, $east, $north);
}

$response = array(
	'type'              => 'FeatureCollection',
	'features'          => $features,
	'envelope'          => $envelope,
	'image_basemap_ids' => array_values(array_unique($image_basemap_ids)),
	'strat_section_ids' => array_values(array_unique($strat_section_ids)),
	'dataset'           => array(
		'id'           => $dataset_id,
		'name'         => $dataset_name,
		'project_id'   => $project_id,
		'project_name' => $project_name,
		'owner_pkey'   => $owner_pkey,
		'is_public'    => $is_public
	)
);

echo json_encode($response);
