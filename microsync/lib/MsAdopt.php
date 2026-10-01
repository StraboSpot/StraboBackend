<?php
/**
 * File: MsAdopt.php
 * Description: Adopting a legacy project row into the sync store (P1-1):
 *              JavaFX-format projects, and new-app projects the batch
 *              converter has not converted, enter sync through the app,
 *              which imports them and pushes into the SAME row (project id,
 *              share codes, viewer links survive).
 *
 *              The row stays sync_format = 'legacy' with sync_state =
 *              'adopting' until the initial upload is done, so every legacy
 *              reader (lists, search, the static project.zip, share codes,
 *              viewer, PDF) keeps working unchanged; only the sync API lets
 *              the owner push into it. POST projects/{pid}/ready then archives
 *              project.zip (plus JavaFX leftovers) like the converter and flips
 *              the row to entity/ready (MsAdopt::finish).
 *
 *              Old-app uploads to an adopting project are refused
 *              (microdb/lib/sync_guard.php). An adoption is dropped (store
 *              rows and blobs removed, the legacy project untouched) when the
 *              owner cancels (DELETE projects/{pid}/adopt) or after 7 days
 *              without a push or upload (worker sweep).
 *
 *              Design: StraboMicro2 repo, docs/specs/collaboration-phase0-design.md
 *              §4.9 (P1-1).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsAdopt {

	const IDLE_DAYS = 7;

	/** Folder entries of a legacy project that move to the archive at ready (besides project.zip). */
	const LEGACY_LEFTOVERS = array('webImages', 'webThumbnails', 'project.pdf');

	public static function archiveDir($pid) {
		return MsStore::filesRoot() . '/_archive/' . (int)$pid;
	}

	private static function zipStat($pid) {
		$zip = MsStore::filesRoot() . '/' . (int)$pid . '/project.zip';
		clearstatcache(true, $zip);
		return is_file($zip) ? array('bytes' => filesize($zip), 'mtime' => filemtime($zip)) : null;
	}

	/** POST projects/{pid}/adopt: start (or resume) adopting my legacy project. */
	public static function start($ctx, $pid) {
		$db = $ctx->db;
		$db->begin();
		try {
			MsStore::lockProject($db, $pid);
			$p = $db->row(
				"SELECT id, strabo_id, userpkey, sync_format, sync_state, head_seq
				   FROM strabomicro.micro_projectmetadata WHERE id = $1 FOR UPDATE",
				array($pid));
			if ($p === null || (int)$p['userpkey'] !== (int)$ctx->me) {
				throw new MsHttpError(404, 'not_found', 'Project not found');
			}
			if ($p['sync_format'] === 'entity') {
				throw new MsHttpError(409, 'already_synced', 'This project is already in the sync store');
			}
			if ($p['sync_state'] === 'adopting') {
				$db->rollback();
				MsHttp::json(200, array('pid' => $pid, 'straboId' => $p['strabo_id'], 'syncState' => 'adopting',
					'headSeq' => (int)$p['head_seq'], 'resumed' => true));
				return;
			}
			$arch = self::archiveDir($pid);
			if (is_file("$arch/project.zip")) {
				throw new MsHttpError(409, 'archive_exists', 'An earlier archive of this project is in the way; contact support');
			}
			if (!is_dir($arch) && !@mkdir($arch, 0775, true) && !is_dir($arch)) {
				throw new MsHttpError(500, 'io', 'Cannot prepare the project archive');
			}
			$state = array('startedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'owner' => (int)$ctx->me,
				'straboId' => $p['strabo_id'], 'zip' => self::zipStat($pid));
			if (@file_put_contents("$arch/adopt.json", json_encode($state, MsHttp::JSON_OUT | JSON_PRETTY_PRINT)) === false) {
				throw new MsHttpError(500, 'io', 'Cannot prepare the project archive');
			}
			// Leftovers of an earlier dropped adoption or reverted conversion.
			foreach (array('micro_blob_refs', 'micro_blobs', 'micro_changes', 'micro_entities', 'micro_pushes',
					'micro_parked_pushes', 'micro_presence', 'micro_members') as $t) {
				$db->q("DELETE FROM strabomicro.$t WHERE project_id = $1", array($pid));
			}
			$db->q(
				"UPDATE strabomicro.micro_projectmetadata
				    SET sync_state = 'adopting', head_seq = 0, views_dirty_since = NULL, views_built_at = NULL
				  WHERE id = $1",
				array($pid));
			$db->q(
				"INSERT INTO strabomicro.micro_members (project_id, user_pkey, role, state, invited_by, responded_at)
				 VALUES ($1, $2, 'owner', 'active', $2, now())",
				array($pid, $ctx->me));
			$db->commit();
		} catch (Exception $e) {
			$db->rollback();
			throw $e;
		}
		MsHttp::json(201, array('pid' => $pid, 'straboId' => $p['strabo_id'], 'syncState' => 'adopting', 'headSeq' => 0));
	}

	/** DELETE projects/{pid}/adopt: give up; the legacy project stays exactly as it was. */
	public static function cancel($ctx, $pid) {
		$p = MsStore::project($ctx->db, $pid, $ctx->me);
		MsStore::requireRole($p, array('owner'));
		if ($p['sync_format'] !== 'legacy' || $p['sync_state'] !== 'adopting') {
			throw new MsHttpError(409, 'not_adopting', 'This project is not being adopted');
		}
		if (!self::drop($ctx->db, $pid)) {
			throw new MsHttpError(409, 'not_adopting', 'This project is not being adopted');
		}
		MsHttp::json(200, array('pid' => $pid, 'syncState' => 'ready', 'syncFormat' => 'legacy'));
	}

	/**
	 * Remove everything an adoption wrote (store rows, staged uploads, blobs,
	 * adopt.json) and put the row back to plain legacy. False when the
	 * project is not adopting (any more).
	 */
	public static function drop($db, $pid) {
		$pid = (int)$pid;
		$db->begin();
		try {
			MsStore::lockProject($db, $pid);
			$state = $db->val(
				"SELECT sync_state FROM strabomicro.micro_projectmetadata
				  WHERE id = $1 AND sync_format = 'legacy' FOR UPDATE",
				array($pid));
			if ($state !== 'adopting') {
				$db->rollback();
				return false;
			}
			$uploads = $db->rows("DELETE FROM strabomicro.micro_uploads WHERE project_id = $1 RETURNING upload_id", array($pid));
			foreach (array('micro_blob_refs', 'micro_blobs', 'micro_changes', 'micro_entities', 'micro_pushes',
					'micro_parked_pushes', 'micro_presence', 'micro_members') as $t) {
				$db->q("DELETE FROM strabomicro.$t WHERE project_id = $1", array($pid));
			}
			$db->q(
				"UPDATE strabomicro.micro_projectmetadata
				    SET sync_state = 'ready', head_seq = 0, views_dirty_since = NULL, views_built_at = NULL
				  WHERE id = $1",
				array($pid));
			$db->commit();
		} catch (Exception $e) {
			$db->rollback();
			throw $e;
		}
		foreach ($uploads as $u) {
			@unlink(MsStore::stagingPath($u['upload_id']));
		}
		$blobs = MsStore::filesRoot() . "/$pid/blobs";
		foreach ((array)@scandir($blobs) as $f) {
			if ($f !== '.' && $f !== '..') {
				@unlink("$blobs/$f");
			}
		}
		@rmdir($blobs);
		$arch = self::archiveDir($pid);
		@unlink("$arch/adopt.json");
		@rmdir($arch);
		return true;
	}

	/** Worker sweep: drop adoptions with no push or upload for IDLE_DAYS. Returns the dropped ids. */
	public static function expireIdle($db) {
		$dropped = array();
		foreach ($db->rows(
			"SELECT p.id FROM strabomicro.micro_projectmetadata p
			  WHERE p.sync_format = 'legacy' AND p.sync_state = 'adopting'
			    AND GREATEST(
			          (SELECT max(m.responded_at) FROM strabomicro.micro_members m WHERE m.project_id = p.id),
			          (SELECT max(c.at) FROM strabomicro.micro_changes c WHERE c.project_id = p.id),
			          (SELECT max(u.updated_at) FROM strabomicro.micro_uploads u WHERE u.project_id = p.id),
			          (SELECT max(b.at) FROM strabomicro.micro_pushes b WHERE b.project_id = p.id)
			        ) < now() - make_interval(days => $1)
			  ORDER BY p.id",
			array(self::IDLE_DAYS)) as $r) {
			if (self::drop($db, (int)$r['id'])) {
				$dropped[] = (int)$r['id'];
			}
		}
		return $dropped;
	}

	/**
	 * POST projects/{pid}/ready for an adopting project ($p from
	 * MsStore::project, project entity already checked): archive project.zip
	 * and the legacy leftovers, journal, flip to entity/ready.
	 */
	public static function finish($ctx, $pid, $p) {
		$db = $ctx->db;
		$root = MsStore::filesRoot() . '/' . (int)$pid;
		$arch = self::archiveDir($pid);
		$moved = array();
		$db->begin();
		try {
			MsStore::lockProject($db, $pid);
			$state = $db->val(
				"SELECT sync_state FROM strabomicro.micro_projectmetadata
				  WHERE id = $1 AND sync_format = 'legacy' FOR UPDATE",
				array($pid));
			if ($state !== 'adopting') {
				throw new MsHttpError(409, 'not_adopting', 'This project is not being adopted');
			}
			$started = json_decode((string)@file_get_contents("$arch/adopt.json"), true);
			if (!is_array($started)) {
				throw new MsHttpError(500, 'io', 'The adoption record is missing; cancel and start again');
			}
			// An old app's upload after the adoption started would be lost: refuse.
			if (self::zipStat($pid) != $started['zip']) {
				throw new MsHttpError(409, 'legacy_changed',
					'The project on the server was changed by an older StraboMicro since this upload started; cancel and start again');
			}
			if (is_file("$arch/project.zip")) {
				throw new MsHttpError(409, 'archive_exists', 'An earlier archive of this project is in the way; contact support');
			}
			foreach (array_merge(array('project.zip'), self::LEGACY_LEFTOVERS) as $name) {
				if (file_exists("$root/$name")) {
					if (file_exists("$arch/$name") || !@rename("$root/$name", "$arch/$name")) {
						throw new MsHttpError(500, 'io', "Cannot archive $name");
					}
					$moved[] = $name;
				}
			}
			$headSeq = (int)$db->val("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
			$journal = array(
				'tool' => 'microsync adopt (POST projects/{pid}/ready)',
				'headSeq' => $headSeq,
				'convertedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				'adoptStartedAt' => $started['startedAt'],
				'projectId' => (int)$pid,
				'straboId' => $p['strabo_id'],
				'owner' => (int)$ctx->me,
				'zip' => $started['zip'],
				'moved' => $moved,
				'rollback' => 'the archive is the exact pre-adoption state (the moved entries); later edits are in the streamed .smz',
			);
			if (@file_put_contents("$arch/journal.json", json_encode($journal, MsHttp::JSON_OUT | JSON_PRETTY_PRINT)) === false) {
				throw new MsHttpError(500, 'io', 'Cannot write the archive journal');
			}
			$db->q(
				"UPDATE strabomicro.micro_projectmetadata
				    SET sync_format = 'entity', sync_state = 'ready', views_dirty_since = now()
				  WHERE id = $1",
				array($pid));
			$db->commit();
		} catch (Exception $e) {
			$db->rollback();
			foreach (array_reverse($moved) as $name) {
				@rename("$arch/$name", "$root/$name");
			}
			if ($moved) {
				@unlink("$arch/journal.json");
			}
			throw $e;
		}
		@unlink("$arch/adopt.json");
		MsWorker::kick($pid);
		MsHttp::json(200, array('pid' => $pid, 'syncState' => 'ready', 'headSeq' => $headSeq, 'adopted' => true));
	}
}
