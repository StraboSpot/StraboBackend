<?php
/**
 * File: sync_guard.php
 * Description: Keeps the legacy StraboMicro code paths away from projects
 *              that use the new sync store (micro_projectmetadata.sync_format
 *              = 'entity', written by /microsync/v1/).
 *
 *              - Lists hide synced projects until the worker has built their
 *                derived rows and files (views_built_at set).
 *              - Legacy uploads (jwtmicrodb and microdb, with or without a
 *                file) refuse synced projects: their rebuild deletes the
 *                project row, which cascades into the entity store. Accepting
 *                single-member uploads (design P0-9) arrives with the
 *                conversion code.
 *              - Legacy deletes of a synced project are allowed only while the
 *                owner is its only active member.
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
	return "({$p}sync_format = 'legacy' OR {$p}views_built_at IS NOT NULL)";
}

/** The caller's project row for a strabo_id: {id, sync_format, views_built_at}, or null. */
function micro_sync_project_row($db, $userpkey, $straboId) {
	return $db->get_row_prepared(
		"SELECT id, sync_format, views_built_at FROM strabomicro.micro_projectmetadata
		  WHERE userpkey = $1 AND strabo_id = $2 ORDER BY id LIMIT 1",
		array((int)$userpkey, (string)$straboId));
}

/** Message refusing a legacy upload over a synced project, or null to proceed. */
function micro_sync_upload_refusal($db, $userpkey, $straboId) {
	$row = micro_sync_project_row($db, $userpkey, $straboId);
	if ($row && $row->sync_format === 'entity') {
		return 'This project is kept in sync by a newer version of StraboMicro. '
			. 'Please update StraboMicro to upload changes to it.';
	}
	return null;
}

/** Message refusing a legacy delete of a shared synced project, or null to proceed. */
function micro_sync_delete_refusal($db, $userpkey, $straboId) {
	$row = micro_sync_project_row($db, $userpkey, $straboId);
	if (!$row || $row->sync_format !== 'entity') {
		return null;
	}
	$others = (int)$db->get_var_prepared(
		"SELECT count(*) FROM strabomicro.micro_members
		  WHERE project_id = $1 AND state = 'active' AND user_pkey <> $2",
		array((int)$row->id, (int)$userpkey));
	if ($others > 0) {
		return 'This project is shared with other people and cannot be deleted here. '
			. 'Manage it from StraboMicro.';
	}
	return null;
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
