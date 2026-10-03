<?php
/**
 * File: MsDelete.php
 * Description: Deleting a synced StraboMicro project (v3 §12b 17p, 17ac,
 *              17ad): the owner's delete keeps the project 30 days, hidden
 *              everywhere, and restorable by the owner; a daily purge then
 *              deletes it for good. strabomicro.micro_deleted_projects holds
 *              one row per deleted project and is never emptied, so members'
 *              copies are told "deleted" (410 project_deleted) even after the
 *              purge, never a plain 404.
 *
 *              While deleted:
 *                - every project call answers 410 project_deleted to people
 *                  who were members when it was deleted (404 to anyone else)
 *                - lists, invitations, downloads, uploads and the worker skip it
 *                - its StraboSearch slice and StraboSamples links are dropped
 *                - its folder moves to straboMicroFiles/_deleted/<pid>
 *                  (denied to the web by the root .htaccess)
 *              Restore brings back the folder and the tombstone goes; the
 *              worker then rebuilds the search slice and StraboSamples links.
 *
 *   DELETE projects/{pid}     soft delete (owner; MsProjects::delete)
 *   micro_delete.php (website: confirm, restore) and microsync/tools/deleted.php (list, restore, purge).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsDelete {

	/** Days a deleted project can be restored before the purge (counted as 24-hour days: no DST shift). */
	const KEEP_DAYS = 30;

	/** SQL condition: the project row aliased $alias is deleted (not restorable or not yet purged alike). */
	public static function deletedSql($alias = 'p') {
		return "EXISTS (SELECT 1 FROM strabomicro.micro_deleted_projects dp WHERE dp.project_id = $alias.id)";
	}

	/** The tombstone of a project id, or null. */
	public static function tombstone($db, $pid) {
		$t = $db->row(
			"SELECT project_id, strabo_id, owner_pkey, name, members::text AS members, deleted_by,
			        " . MsDb::iso('deleted_at') . " AS deleted_at,
			        " . MsDb::iso("(deleted_at + make_interval(hours => " . (self::KEEP_DAYS * 24) . "))") . " AS restorable_until,
			        " . MsDb::iso('purged_at') . " AS purged_at,
			        (purged_at IS NULL AND deleted_at > now() - make_interval(hours => " . (self::KEEP_DAYS * 24) . ")) AS restorable
			   FROM strabomicro.micro_deleted_projects WHERE project_id = $1",
			array((int)$pid));
		if ($t === null) {
			return null;
		}
		$t['members'] = array_map('intval', array_filter(explode(',', trim($t['members'], '{}')), 'strlen'));
		$t['restorable'] = MsDb::bool($t['restorable']);
		return $t;
	}

	/**
	 * 410 for a deleted project, or 404 for someone who was not a member
	 * when it was deleted (they learn nothing about it). $knows: they know
	 * of it anyway (an invitation to it).
	 */
	public static function deletedError($db, $t, $me, $knows = false) {
		if (!$knows && !in_array((int)$me, $t['members'], true)) {
			return new MsHttpError(404, 'not_found', 'Project not found');
		}
		$by = (int)$t['deleted_by'];
		$users = MsStore::users($db, array($by));
		$name = $t['name'] === null ? '' : $t['name'];
		return new MsHttpError(410, 'project_deleted',
			($name === '' ? 'This project' : "\"$name\"") . ' was deleted from StraboSpot',
			array(
				'byMe'            => $by === (int)$me,
				'deletedBy'       => MsStore::user($users, $by),
				'deletedAt'       => $t['deleted_at'],
				'restorableUntil' => $t['restorable'] ? $t['restorable_until'] : null,
				'project'         => array('pid' => (int)$t['project_id'], 'name' => $name),
			));
	}

	/** The project's name as members see it now (the project entity's, else the row's). */
	public static function liveName($db, $pid) {
		$name = $db->val(
			"SELECT COALESCE(e.body->>'name', p.name)
			   FROM strabomicro.micro_projectmetadata p
			   LEFT JOIN strabomicro.micro_entities e
			          ON e.project_id = p.id AND e.entity_type = 'project' AND e.entity_id = p.strabo_id
			  WHERE p.id = $1",
			array((int)$pid));
		return $name === null ? '' : (string)$name;
	}

	public static function liveFolder($pid) {
		return MsStore::filesRoot() . '/' . (int)$pid;
	}

	public static function deletedFolder($pid) {
		return MsStore::filesRoot() . '/_deleted/' . (int)$pid;
	}

	/**
	 * Delete a synced project (the caller checked that $by owns it). Returns
	 * the tombstone. $strabodb: the legacy db wrapper (search and samples
	 * helpers); $db: MsDb on the same connection.
	 */
	public static function softDelete($strabodb, $db, $pid, $by) {
		$pid = (int)$pid;
		$db->begin();
		MsStore::lockProject($db, $pid);
		$p = $db->row(
			"SELECT p.id, p.strabo_id, p.userpkey, COALESCE(e.body->>'name', p.name) AS name
			   FROM strabomicro.micro_projectmetadata p
			   LEFT JOIN strabomicro.micro_entities e
			          ON e.project_id = p.id AND e.entity_type = 'project' AND e.entity_id = p.strabo_id
			  WHERE p.id = $1 AND (p.sync_format = 'entity' OR p.sync_state = 'adopting')",
			array($pid));
		if ($p === null) {
			$db->rollback();
			throw new MsHttpError(404, 'not_found', 'Project not found');
		}
		$existing = self::tombstone($db, $pid);
		if ($existing !== null) {
			$db->rollback();
			return $existing;
		}
		$members = array_map('intval', array_column($db->rows(
			"SELECT user_pkey FROM strabomicro.micro_members WHERE project_id = $1 AND state = 'active'",
			array($pid)), 'user_pkey'));
		$members[] = (int)$p['userpkey'];
		$members = array_values(array_unique($members));
		$db->q(
			"INSERT INTO strabomicro.micro_deleted_projects
			   (project_id, strabo_id, owner_pkey, name, members, deleted_by)
			 VALUES ($1, $2, $3, $4, $5::integer[], $6)",
			array($pid, $p['strabo_id'], (int)$p['userpkey'], $p['name'], '{' . implode(',', $members) . '}', (int)$by));
		// Uploads in progress go with it
		foreach ($db->rows("DELETE FROM strabomicro.micro_uploads WHERE project_id = $1 RETURNING upload_id", array($pid)) as $u) {
			@unlink(MsStore::stagingPath($u['upload_id']));
		}
		$db->q("DELETE FROM strabomicro.micro_presence WHERE project_id = $1", array($pid));
		$db->commit();

		// Out of StraboSearch and StraboSamples (the worker puts them back on restore)
		$root = dirname(__DIR__, 2);
		require_once $root . '/microdb/lib/search_sync.php';
		require_once $root . '/microdb/lib/sample_sync.php';
		micro_search_sync_remove_project($strabodb, $p['strabo_id'], (int)$p['userpkey']);
		micro_sample_sync_remove_project($strabodb, $p['strabo_id'], (int)$p['userpkey']);

		// The delete stands if the folder cannot move (the purge removes either place)
		try {
			self::moveFolder(self::liveFolder($pid), self::deletedFolder($pid));
		} catch (RuntimeException $e) {
			error_log("microsync delete $pid: " . $e->getMessage());
		}
		return self::tombstone($db, $pid);
	}

	/**
	 * Bring a deleted project back (the caller checked that $by is its
	 * owner). Throws 409 not_restorable once the 30 days have passed or it
	 * was purged. Everything comes back as it was; the worker rebuilds the
	 * derived views (search slice, StraboSamples links, files) on its next sweep.
	 */
	public static function restore($db, $pid, $by) {
		$pid = (int)$pid;
		$t = self::tombstone($db, $pid);
		if ($t === null || (int)$t['owner_pkey'] !== (int)$by) {
			throw new MsHttpError(404, 'not_found', 'Deleted project not found');
		}
		if (!$t['restorable']) {
			throw new MsHttpError(409, 'not_restorable', 'This project can no longer be restored');
		}
		self::moveFolder(self::deletedFolder($pid), self::liveFolder($pid));
		$db->begin();
		MsStore::lockProject($db, $pid);
		$db->q("DELETE FROM strabomicro.micro_deleted_projects WHERE project_id = $1 AND purged_at IS NULL", array($pid));
		$db->q(
			"UPDATE strabomicro.micro_projectmetadata
			    SET views_dirty_since = now(), files_dirty = true
			  WHERE id = $1",
			array($pid));
		$db->commit();
		return $t;
	}

	/** Deleted projects of an owner that can still be restored (website list). */
	public static function restorableFor($db, $owner) {
		$out = array();
		foreach ($db->rows(
			"SELECT project_id FROM strabomicro.micro_deleted_projects
			  WHERE owner_pkey = $1 AND purged_at IS NULL
			    AND deleted_at > now() - make_interval(hours => " . (self::KEEP_DAYS * 24) . ")
			  ORDER BY deleted_at DESC",
			array((int)$owner)) as $r) {
			$out[] = self::tombstone($db, (int)$r['project_id']);
		}
		return $out;
	}

	/** Purge every project deleted more than KEEP_DAYS ago; returns the purged ids. */
	public static function purgeDue($strabodb, $db) {
		$done = array();
		foreach ($db->rows(
			"SELECT project_id FROM strabomicro.micro_deleted_projects
			  WHERE purged_at IS NULL AND deleted_at <= now() - make_interval(hours => " . (self::KEEP_DAYS * 24) . ")
			  ORDER BY deleted_at",
			array()) as $r) {
			self::purge($strabodb, $db, (int)$r['project_id']);
			$done[] = (int)$r['project_id'];
		}
		return $done;
	}

	/**
	 * Delete a deleted project for good: its rows (the project row cascades
	 * into the sync store: entities, history, blobs, refs, members, parked
	 * pushes) and its folder. The tombstone stays, with purged_at.
	 */
	public static function purge($strabodb, $db, $pid) {
		$pid = (int)$pid;
		$t = self::tombstone($db, $pid);
		if ($t === null || $t['purged_at'] !== null) {
			return false;
		}
		$exists = $db->val("SELECT 1 FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
		if ($exists !== null) {
			$sm = new StraboMicro(null, (int)$t['owner_pkey'], $strabodb);
			$sm->deleteProjectRows($t['strabo_id']);
			// deleteProjectRows goes by straboId and owner; this row in any case
			$db->q("DELETE FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
		}
		foreach (array(self::deletedFolder($pid), self::liveFolder($pid)) as $dir) {
			if (is_dir($dir)) {
				exec('rm -rf ' . escapeshellarg($dir));
			}
		}
		@unlink(self::liveFolder($pid) . '.zip');
		$db->q("UPDATE strabomicro.micro_deleted_projects SET purged_at = now() WHERE project_id = $1", array($pid));
		return true;
	}

	/** Move a project folder (same volume: a rename); a missing source is fine. */
	private static function moveFolder($from, $to) {
		if (!is_dir($from)) {
			return;
		}
		$parent = dirname($to);
		if (!is_dir($parent)) {
			@mkdir($parent, 0775, true);
		}
		if (is_dir($to)) {
			throw new RuntimeException("Cannot move $from: $to exists");
		}
		if (!@rename($from, $to)) {
			throw new RuntimeException("Cannot move $from to $to");
		}
	}
}
