<?php
/**
 * File: search_sync.php
 * Description: StraboSearch §5.3 live-sync hooks for the StraboField write
 *              paths. Thin wrappers around StraboSearchSync, mirroring the
 *              placement pattern of db/lib/sample_sync.php: require_once at
 *              each call site inside strabospotclass.php / the bulk
 *              controllers, free functions guarded by function_exists.
 *
 * Placement contract (mirrors the sample-sync hooks):
 *   - insertSpot create/update: touch AFTER the Neo4j write, BEFORE
 *     field_sample_sync_spot — so the spine sync's own search hook
 *     (touchSample, wired inside StraboSamplesService) resolves field
 *     links against the freshly indexed spot.
 *   - deleteSingleSpot: remove AFTER the DETACH DELETE (identity is
 *     denormalized in the index; nothing must be read pre-delete).
 *   - deleteSingleDataset / deleteDatasetSpots: enumerate + remove BEFORE
 *     the cascade delete (spot ids are only enumerable pre-delete;
 *     item_hit carries no dataset id to scope a post-hoc sweep by).
 *   - deleteProject / deleteProjectDatasets: slice delete by project id —
 *     placement-independent, wired beside the pre-delete sample hooks.
 *   - Bulk uploads (§5.3.4): controllers suppress per-item touches before
 *     their spot loop and call field_search_sync_dataset once beside the
 *     existing end-of-dataset buildPgDataset call. remove* hooks stay
 *     active during suppression (bulk flows delete server-only spots
 *     mid-loop).
 *
 * Every underlying StraboSearchSync method is never-throw (§5.3.3): a sync
 * failure logs and returns false, the user write proceeds.
 *
 * @package    StraboField
 * @copyright  2026 StraboSpot
 */

require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';

if (!function_exists('field_search_sync_touch_spot')) {

function field_search_sync_touch_spot($db, $neodb, $spotId, $userpkey) {
	return StraboSearchSync::touchSpot($db, $neodb, $spotId, $userpkey, false);
}

function field_search_sync_remove_spot($db, $spotId, $userpkey) {
	return StraboSearchSync::removeSpot($db, $spotId, $userpkey);
}

/**
 * Pre-delete hook for dataset-grain cascade deletes: enumerate the doomed
 * spots while they still exist, then drop their index rows.
 */
function field_search_sync_remove_dataset($db, $neodb, $datasetId, $userpkey) {
	$datasetId = (int)$datasetId;
	$userpkey  = (int)$userpkey;
	if ($datasetId <= 0) return false;
	try {
		$records = $neodb->query(
			"MATCH (d:Dataset {id:$datasetId, userpkey:$userpkey})-[:HAS_SPOT]->(s:Spot)
			 RETURN DISTINCT s.id AS sid"
		);
		$spotIds = array();
		if (is_array($records)) {
			foreach ($records as $rec) {
				$sid = $rec->value('sid');
				if ($sid !== null && $sid !== '') $spotIds[] = $sid;
			}
		}
		return StraboSearchSync::removeSpots($db, $spotIds, $userpkey);
	} catch (\Throwable $e) {
		error_log('[strabosearch-sync] field_search_sync_remove_dataset '
			. $datasetId . '/' . $userpkey . ' FAILED: ' . $e->getMessage());
		return false;
	}
}

function field_search_sync_remove_project($db, $projectId, $userpkey) {
	return StraboSearchSync::removeFieldProject($db, $projectId, $userpkey);
}

function field_search_sync_touch_image($db, $neodb, $imageId, $userpkey) {
	return StraboSearchSync::touchImage($db, $neodb, $imageId, $userpkey);
}

function field_search_sync_remove_image($db, $imageId, $userpkey) {
	return StraboSearchSync::removeImage($db, $imageId, $userpkey);
}

function field_search_sync_dataset($db, $neodb, $datasetId, $userpkey) {
	return StraboSearchSync::syncFieldDataset($db, $neodb, $datasetId, $userpkey);
}

function field_search_sync_suppress() { StraboSearchSync::suppressFieldItemTouches(); }
function field_search_sync_resume()   { StraboSearchSync::resumeFieldItemTouches(); }

/**
 * Refresh the denormalized project_name / project_ispublic on the
 * project's index slice from the just-rebuilt PG `project` row. Called
 * from insertProject AFTER buildPgProject (which is what makes the PG row
 * current). Cheap UPDATE — no re-extract; the documented Field-rename
 * searchtext staleness applies (heals on next spot touch / full extract).
 */
function field_search_sync_project_meta($db, $projectId, $userpkey) {
	try {
		$row = $db->get_row("SELECT project_name, ispublic FROM project
			WHERE strabo_project_id = '" . pg_escape_string((string)$projectId) . "'
			  AND user_pkey = " . (int)$userpkey . " LIMIT 1");
		if (!$row) return false;
		return StraboSearchSync::touchProjectMeta($db, 'field', $projectId, (int)$userpkey,
			$row->project_name, ($row->ispublic === 't' || $row->ispublic === true));
	} catch (\Throwable $e) {
		error_log('[strabosearch-sync] field_search_sync_project_meta '
			. $projectId . '/' . $userpkey . ' FAILED: ' . $e->getMessage());
		return false;
	}
}

}
