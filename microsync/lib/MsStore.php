<?php
/**
 * File: MsStore.php
 * Description: Shared data access for the /microsync/v1/ API: project
 *              access and roles, entity rows, entity state as written to the
 *              change log, change log writes, user summaries, file paths.
 *
 *              Change log before/after hold an entity STATE object:
 *              {"parentType", "parentId", "body", "childOrder", "refs"}
 *              (after = null for a delete, before = null for a create), so a
 *              pull can apply a change without further lookups.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsStore {

	const ROLES_WRITE = 'owner,editor,contributor';

	/**
	 * Load a synced project and the caller's membership. 404 unless the
	 * project uses the entity format and the caller is an active member
	 * ($allowInactive: also return removed/downgraded rows, for parking).
	 */
	public static function project($db, $pid, $me, $allowInactive = false) {
		$row = $db->row(
			"SELECT p.id, p.strabo_id, p.name, p.userpkey, p.sync_format, p.sync_state, p.head_seq,
			        " . MsDb::iso('p.views_built_at') . " AS views_built_at,
			        m.role, m.state AS member_state, m.removed_at
			   FROM strabomicro.micro_projectmetadata p
			   LEFT JOIN strabomicro.micro_members m ON m.project_id = p.id AND m.user_pkey = $2
			  WHERE p.id = $1",
			array($pid, $me));
		if ($row === null || $row['sync_format'] !== 'entity' || $row['member_state'] === null) {
			throw new MsHttpError(404, 'not_found', 'Project not found');
		}
		if ($row['member_state'] !== 'active' && !$allowInactive) {
			throw new MsHttpError(404, 'not_found', 'Project not found');
		}
		$row['id'] = (int)$row['id'];
		$row['head_seq'] = (int)$row['head_seq'];
		return $row;
	}

	public static function canWrite($role) {
		return in_array($role, array('owner', 'editor', 'contributor'), true);
	}

	public static function requireRole($project, $roles) {
		if (!in_array($project['role'], $roles, true)) {
			throw new MsHttpError(403, 'forbidden', 'Your role in this project does not allow this');
		}
	}

	/** One entity row with decoded body/childOrder, or null. */
	public static function entity($db, $pid, $type, $id) {
		$row = $db->row(
			"SELECT entity_type, entity_id, parent_type, parent_id, body::text AS body,
			        child_order::text AS child_order, version, created_by, updated_by,
			        " . MsDb::iso('updated_at') . " AS updated_at,
			        " . MsDb::iso('deleted_at') . " AS deleted_at, deleted_by, deleted_root
			   FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3",
			array($pid, $type, $id));
		return $row === null ? null : self::decodeRow($row);
	}

	public static function decodeRow($row) {
		$row['body'] = json_decode($row['body']);
		$row['child_order'] = $row['child_order'] === null ? array() : json_decode($row['child_order'], true);
		$row['version'] = (int)$row['version'];
		$row['created_by'] = $row['created_by'] === null ? null : (int)$row['created_by'];
		return $row;
	}

	public static function isLive($row) {
		return $row !== null && $row['deleted_at'] === null;
	}

	/** Blob refs of one entity: role => sha256. */
	public static function refs($db, $pid, $type, $id) {
		$out = array();
		foreach ($db->rows(
			"SELECT role, sha256 FROM strabomicro.micro_blob_refs
			  WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3 ORDER BY role",
			array($pid, $type, $id)) as $r) {
			$out[$r['role']] = $r['sha256'];
		}
		return $out;
	}

	/** Entity state object for the change log (see file header). */
	public static function state($db, $pid, $row) {
		$refs = self::refs($db, $pid, $row['entity_type'], $row['entity_id']);
		return array(
			'parentType' => $row['parent_type'],
			'parentId'   => $row['parent_id'],
			'body'       => $row['body'],
			'childOrder' => empty($row['child_order']) ? new stdClass() : $row['child_order'],
			'refs'       => empty($refs) ? new stdClass() : $refs,
		);
	}

	/**
	 * Per-project write lock (P0-6), held until the transaction ends. Every
	 * writer of micro_changes takes it, so seqs of one project are assigned
	 * in commit order.
	 */
	public static function lockProject($db, $pid) {
		$db->q("SELECT pg_advisory_xact_lock(hashtext('microsync-push'), $1)", array($pid));
	}

	/** After accepted changes: move head_seq and mark derived views dirty. */
	public static function bumpHead($db, $pid, $seq) {
		$db->q(
			"UPDATE strabomicro.micro_projectmetadata
			    SET head_seq = GREATEST(head_seq, $2), views_dirty_since = COALESCE(views_dirty_since, now())
			  WHERE id = $1",
			array($pid, $seq));
	}

	/** Append one change log row; returns its seq. */
	public static function logChange($db, $ctx, $pid, $type, $id, $op, $version, $paths, $before, $after) {
		return (int)$db->val(
			"INSERT INTO strabomicro.micro_changes
			   (project_id, push_id, entity_type, entity_id, op, version, changed_paths, before, after, user_pkey)
			 VALUES ($1, $2, $3, $4, $5, $6,
			         CASE WHEN $7::jsonb IS NULL THEN NULL
			              ELSE ARRAY(SELECT jsonb_array_elements_text($7::jsonb)) END,
			         $8::jsonb, $9::jsonb, $10)
			 RETURNING seq",
			array($pid, $ctx->pushId, $type, $id, $op, $version,
			      $paths === null ? null : MsHttp::encode(array_values($paths)),
			      $before === null ? null : MsHttp::encode($before),
			      $after === null ? null : MsHttp::encode($after),
			      $ctx->me));
	}

	/** pkey => {"pkey", "name"} (plus "email" when $withEmail). */
	public static function users($db, $pkeys, $withEmail = false) {
		$pkeys = array_values(array_unique(array_filter(array_map('intval', $pkeys))));
		$out = array();
		if (empty($pkeys)) {
			return $out;
		}
		foreach ($db->rows(
			"SELECT pkey, firstname, lastname, email FROM public.users
			  WHERE pkey = ANY (ARRAY(SELECT jsonb_array_elements_text($1::jsonb)::int))",
			array(json_encode($pkeys))) as $u) {
			$entry = array('pkey' => (int)$u['pkey'], 'name' => trim($u['firstname'] . ' ' . $u['lastname']));
			if ($withEmail) {
				$entry['email'] = $u['email'];
			}
			$out[(int)$u['pkey']] = $entry;
		}
		return $out;
	}

	public static function user($users, $pkey) {
		if ($pkey === null) {
			return null;
		}
		$pkey = (int)$pkey;
		return isset($users[$pkey]) ? $users[$pkey] : array('pkey' => $pkey, 'name' => '');
	}

	/** straboMicroFiles root (MICROSYNC_FILES_ROOT overrides, for tests). */
	public static function filesRoot() {
		if (defined('MICROSYNC_FILES_ROOT')) {
			return rtrim(MICROSYNC_FILES_ROOT, '/');
		}
		return dirname(__DIR__, 2) . '/straboMicroFiles';
	}

	public static function blobPath($pid, $sha) {
		return self::filesRoot() . '/' . (int)$pid . '/blobs/' . $sha;
	}

	public static function stagingPath($uploadId) {
		return self::filesRoot() . '/_staging/' . $uploadId;
	}
}
