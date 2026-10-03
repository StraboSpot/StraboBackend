<?php
/**
 * File: sync_guard.php
 * Description: Keeps the legacy StraboMicro code paths away from projects
 *              that use the new sync store (micro_projectmetadata.sync_format
 *              = 'entity', written by /microsync/v1/).
 *
 *              - Lists hide synced projects until the worker has built their
 *                derived rows and files (views_built_at set).
 *              - Legacy uploads to synced projects: jwtmicrodb (the new app)
 *                replaces the project in place when the owner is its only
 *                active member (design P0-9, micro_sync_upload_plan, then
 *                MsConvert::replace); otherwise, and always for microdb (the
 *                retired JavaFX API), they are refused: the old rebuild
 *                deletes the project row, which cascades into the store.
 *              - Legacy deletes of a synced project the owner has to
 *                themselves are the 30-day delete (microsync/lib/MsDelete.php,
 *                v3 17ac); a shared one is refused until the website's
 *                confirmation names its members (stage 6c).
 *              - A deleted synced project is hidden from lists and refuses
 *                uploads until its owner restores it.
 *              - Downloads: synced projects have no static project.zip; every
 *                download door streams one (microsync/lib/MsSmz.php), and the
 *                sizes the app shows come from micro_sync_download_bytes().
 *
 *              Design: StraboMicro2 repo, docs/specs/collaboration-phase0-design.md §4.7.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

/**
 * SQL condition that is true for projects legacy lists may show.
 * $alias: table alias or name qualifying the columns ('' for none).
 */
function micro_sync_visible_sql($alias = '') {
	$p = $alias === '' ? '' : $alias . '.';
	$id = $alias === '' ? 'micro_projectmetadata.id' : $alias . '.id';
	return "({$p}sync_format = 'legacy' OR {$p}views_built_at IS NOT NULL)"
		. " AND NOT EXISTS (SELECT 1 FROM strabomicro.micro_deleted_projects dp WHERE dp.project_id = $id)";
}

/** True when the project (by id) was deleted from StraboSpot by its owner (v3 17ac). */
function micro_sync_is_deleted($db, $projectId) {
	return $db->get_var_prepared(
		"SELECT 1 FROM strabomicro.micro_deleted_projects WHERE project_id = $1",
		array((int)$projectId)) !== null;
}

function micro_sync_deleted_message() {
	return 'This project was deleted from StraboSpot. Its owner can restore it from My StraboMicro Data '
		. 'within 30 days of the delete.';
}

/** The microsync classes, for legacy code that hands a synced project over to them. */
function micro_sync_load_lib() {
	$lib = __DIR__ . '/../../microsync/lib';
	foreach (array('MsHttp', 'MsDb', 'MsModel', 'MsStore', 'MsDelete') as $c) {
		require_once "$lib/$c.php";
	}
}

/** The caller's project row for a strabo_id: {id, sync_format, sync_state, views_built_at}, or null. */
function micro_sync_project_row($db, $userpkey, $straboId) {
	return $db->get_row_prepared(
		"SELECT id, sync_format, sync_state, views_built_at FROM strabomicro.micro_projectmetadata
		  WHERE userpkey = $1 AND strabo_id = $2 ORDER BY id LIMIT 1",
		array((int)$userpkey, (string)$straboId));
}

/**
 * A legacy project the new app is adopting into the sync store (P1-1): it
 * stays legacy for every reader until the app's upload is done, but an old
 * app's upload now would be lost from the synced project, so it is refused.
 */
function micro_sync_adopting($row) {
	return $row && $row->sync_format === 'legacy' && $row->sync_state === 'adopting';
}

function micro_sync_adopting_message() {
	return 'This project is being set up for sync by a newer version of StraboMicro. '
		. 'Please update StraboMicro to upload changes to it.';
}

/** Message refusing a legacy upload over a synced project, or null to proceed. */
function micro_sync_upload_refusal($db, $userpkey, $straboId) {
	$row = micro_sync_project_row($db, $userpkey, $straboId);
	if ($row && micro_sync_is_deleted($db, $row->id)) {
		return micro_sync_deleted_message();
	}
	if (micro_sync_adopting($row)) {
		return micro_sync_adopting_message();
	}
	if ($row && $row->sync_format === 'entity') {
		return 'This project is kept in sync by a newer version of StraboMicro. '
			. 'Please update StraboMicro to upload changes to it.';
	}
	return null;
}

/**
 * How a jwtmicrodb upload of $straboId by $userpkey goes: null = the legacy
 * path (no synced project), array('id' => pid) = P0-9 replace of a synced
 * project the uploader owns alone, string = refusal message (shared, or
 * its initial upload not finished).
 */
function micro_sync_upload_plan($db, $userpkey, $straboId) {
	$row = micro_sync_project_row($db, $userpkey, $straboId);
	if ($row && micro_sync_is_deleted($db, $row->id)) {
		return micro_sync_deleted_message();
	}
	if (micro_sync_adopting($row)) {
		return micro_sync_adopting_message();
	}
	if (!$row || $row->sync_format !== 'entity') {
		return null;
	}
	$state = $db->get_var_prepared(
		"SELECT sync_state FROM strabomicro.micro_projectmetadata WHERE id = $1", array((int)$row->id));
	$others = (int)$db->get_var_prepared(
		"SELECT count(*) FROM strabomicro.micro_members
		  WHERE project_id = $1 AND state = 'active' AND user_pkey <> $2",
		array((int)$row->id, (int)$userpkey));
	if ($state !== 'ready' || $others > 0) {
		return 'This project is shared with other people and kept in sync by a newer version of StraboMicro. '
			. 'Please update StraboMicro to upload changes to it.';
	}
	return array('id' => (int)$row->id);
}

/**
 * A legacy delete (website, old app) of $straboId by $userpkey: null = the
 * legacy path (not synced, delete for good), array('soft' => pid) = the
 * 30-day delete of a synced project the owner has to themselves (v3 17ac),
 * array('done' => pid) = already deleted, string = refusal (shared: the
 * website's confirmation naming its members comes with stage 6c).
 */
function micro_sync_delete_plan($db, $userpkey, $straboId) {
	$row = micro_sync_project_row($db, $userpkey, $straboId);
	if (!$row || ($row->sync_format !== 'entity' && $row->sync_state !== 'adopting')) {
		return null;
	}
	if (micro_sync_is_deleted($db, $row->id)) {
		return array('done' => (int)$row->id);
	}
	$others = (int)$db->get_var_prepared(
		"SELECT count(*) FROM strabomicro.micro_members
		  WHERE project_id = $1 AND state = 'active' AND user_pkey <> $2",
		array((int)$row->id, (int)$userpkey));
	if ($others > 0) {
		return 'This project is shared with other people and cannot be deleted here. '
			. 'Manage it from StraboMicro.';
	}
	return array('soft' => (int)$row->id);
}

/**
 * Run a legacy delete's plan for a synced project: the 30-day delete, or
 * the refusal. Returns a refusal message, true when it was handled here, or
 * null for the legacy path ($db: the legacy wrapper).
 */
function micro_sync_delete($db, $userpkey, $straboId) {
	$plan = micro_sync_delete_plan($db, $userpkey, $straboId);
	if ($plan === null || is_string($plan)) {
		return $plan;
	}
	if (isset($plan['soft'])) {
		micro_sync_load_lib();
		MsDelete::softDelete($db, new MsDb($db), $plan['soft'], (int)$userpkey);
	}
	return true;
}

/** True when the project (by id) is synced but not yet built by the worker. */
function micro_sync_is_unbuilt($db, $projectId) {
	$v = $db->get_var_prepared(
		"SELECT (sync_format = 'entity' AND views_built_at IS NULL) FROM strabomicro.micro_projectmetadata WHERE id = $1",
		array((int)$projectId));
	return $v === 't';
}

/**
 * Size in bytes of the .smz a download of this project (by id) serves:
 * the static project.zip for legacy projects, the exact length of the
 * streamed archive for synced ones. 0 when there is nothing to download.
 * $syncFormat: the row's sync_format when the caller already has it.
 */
function micro_sync_download_bytes($db, $projectId, $syncFormat = null) {
	$fmt = $syncFormat !== null ? $syncFormat : $db->get_var_prepared(
		"SELECT sync_format FROM strabomicro.micro_projectmetadata WHERE id = $1",
		array((int)$projectId));
	if ($fmt === 'entity') {
		require_once __DIR__ . '/../../microsync/lib/MsSmz.php';
		return MsSmz::syncedRow($db, $projectId) === null ? 0 : MsSmz::length($db, $projectId);
	}
	$zip = dirname(__DIR__, 2) . '/straboMicroFiles/' . (int)$projectId . '/project.zip';
	return is_file($zip) ? filesize($zip) : 0;
}
