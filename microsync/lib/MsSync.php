<?php
/**
 * File: MsSync.php
 * Description: Push, pull (changes), and history for the /microsync/v1/ API.
 *
 *              Push (design §4.2, P0-6): one transaction per push holding a
 *              per-project advisory lock. Every change is accepted or
 *              rejected on its own; a create whose parent (or nesting
 *              parent) was rejected in the same push is rejected too
 *              (parent_rejected). A create must come after the creates it
 *              depends on in the changes array.
 *
 *              Rules settled while implementing (2026-09-30):
 *              - childOrder-only updates need no baseVersion, never
 *                conflict, and do not bump the version (v3 §4.3). Any
 *                writer may send one, also a Contributor for someone
 *                else's entity (adding a child reorders the parent).
 *                Removed ids are kept, unknown ids dropped (filterLiveChildren).
 *              - Delete tombstones the entity and every live descendant,
 *                including micrographs nested by parentID; each gets
 *                deleted_root = the deleted entity. A Contributor may delete
 *                only when every entity in that cascade is theirs.
 *              - A delete may carry cascadeVersions ("type:id" => version
 *                the client knows beneath it); a live descendant missing
 *                from it or at another version is a conflict (changedBeneath
 *                names it), so an edit or addition beneath is never deleted
 *                by someone who has not seen it (2026-10-01).
 *              - Restore is for Owners and Editors; cascade restores the
 *                descendants tombstoned by the same delete event.
 *              - A micrograph may change sample only if its own parentID
 *                (if any) is in the new sample and no live micrograph is
 *                nested under it.
 *              - A parentID naming a micrograph that never existed in the
 *                project is kept as-is (legacy data has such dangling
 *                references; the app tolerates them). A deleted one is
 *                parent_deleted.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

/** The change targets (or depends on) a tombstoned entity. */
class MsParentDeleted extends Exception {
	public $row;

	public function __construct($row) {
		parent::__construct('parent deleted');
		$this->row = $row;
	}
}

class MsSync {

	const MAX_CHANGES = 500;
	const MAX_BYTES = 5242880;

	const ENTITY_COLUMNS = "entity_type, entity_id, parent_type, parent_id, body::text AS body,
		child_order::text AS child_order, version, created_by, updated_by,
		to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS updated_at,
		to_char(deleted_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS deleted_at,
		deleted_by, deleted_root";

	// ------------------------------------------------------------------
	// POST projects/{pid}/push
	// ------------------------------------------------------------------

	public static function push($ctx, $pid) {
		$db = $ctx->db;
		$in = MsHttp::readJson(self::MAX_BYTES);
		$pushId = MsHttp::prop($in, 'pushId');
		$clientId = MsHttp::prop($in, 'clientId');
		$changes = MsHttp::prop($in, 'changes');
		if (!MsHttp::isUuid($pushId)) {
			throw new MsHttpError(400, 'bad_request', 'pushId must be a UUID');
		}
		$pushId = strtolower($pushId);
		if ($clientId !== null && (!is_string($clientId) || strlen($clientId) > 200)) {
			throw new MsHttpError(400, 'bad_request', 'clientId must be a string');
		}
		if (!is_array($changes) || count($changes) === 0) {
			throw new MsHttpError(400, 'bad_request', 'changes must be a non-empty array');
		}
		if (count($changes) > self::MAX_CHANGES) {
			throw new MsHttpError(413, 'too_large', 'At most ' . self::MAX_CHANGES . ' changes per push');
		}

		MsStore::project($db, $pid, $ctx->me, true); // 404 before taking the lock
		$db->begin();
		MsStore::lockProject($db, $pid);

		// Idempotency: a retried push gets the stored result.
		$prev = $db->row(
			"SELECT project_id, user_pkey, result::text AS result FROM strabomicro.micro_pushes WHERE push_id = $1",
			array($pushId));
		if ($prev !== null) {
			$db->rollback();
			if ((int)$prev['project_id'] !== $pid || (int)$prev['user_pkey'] !== $ctx->me) {
				throw new MsHttpError(409, 'push_id_reused', 'This pushId was already used for another push');
			}
			http_response_code(200);
			header('Content-Type: application/json');
			echo $prev['result'];
			return;
		}

		// Membership again, now under the lock.
		$p = MsStore::project($db, $pid, $ctx->me, true);
		if ($p['member_state'] !== 'active') {
			self::parkOrRefuse($ctx, $p, $in);
			return;
		}

		$ctx->pushId = $pushId;
		$ctx->project = $p;
		$results = self::applyAll($ctx, $pid, $changes);
		$results = self::parkRoleRefusals($ctx, $p, $pushId, $clientId, $changes, $results);

		// Cascades write more rows than they report, so read the head back
		// (rows from rolled-back savepoints are gone; the lock is held).
		$head = (int)$db->val(
			"SELECT COALESCE(MAX(seq), 0) FROM strabomicro.micro_changes WHERE project_id = $1", array($pid));
		if ($head > $p['head_seq']) {
			MsStore::bumpHead($db, $pid, $head);
		}
		$response = array('headSeq' => $head, 'results' => $results);
		$db->q(
			"INSERT INTO strabomicro.micro_pushes (push_id, project_id, user_pkey, client_id, result)
			 VALUES ($1, $2, $3, $4, $5::json)",
			array($pushId, $pid, $ctx->me, $clientId, MsHttp::encode($response)));
		$db->commit();
		if ($head > $p['head_seq']) {
			MsWorker::kick($pid);
		}
		MsHttp::json(200, $response);
	}

	/**
	 * Push from a member who is no longer active (v3 §3.2): a removed
	 * member's first push after removal is parked for the owner; later ones
	 * are refused. Invited/declined users have no access at all.
	 */
	private static function parkOrRefuse($ctx, $p, $in) {
		$db = $ctx->db;
		if ($p['member_state'] !== 'removed') {
			$db->rollback();
			throw new MsHttpError(404, 'not_found', 'Project not found');
		}
		$already = $db->val(
			"SELECT 1 FROM strabomicro.micro_parked_pushes
			  WHERE project_id = $1 AND user_pkey = $2 AND parked_at >= $3::timestamptz LIMIT 1",
			array($p['id'], $ctx->me, $p['removed_at']));
		if ($already !== null) {
			$db->rollback();
			throw MsStore::removedError($db, $p, $ctx->me, 'access_removed', 'You no longer have access to this project');
		}
		$db->q(
			"INSERT INTO strabomicro.micro_parked_pushes (project_id, user_pkey, payload) VALUES ($1, $2, $3::json)",
			array($p['id'], $ctx->me, MsHttp::encode($in)));
		$db->commit();
		throw MsStore::removedError($db, $p, $ctx->me, 'access_changed',
			'Your access to this project changed. Your changes were sent to the project owner for review.',
			array('parked' => true));
	}

	/** Refusals caused by the member's role (MsSync::forbidden reasons). */
	const ROLE_REASONS = array('viewer', 'contributor_not_creator', 'settings', 'editor_required', 'cascade_includes_others');

	/**
	 * After a role change (17k), changes the member's role refuses are parked
	 * for the owner's review in one parked push (payload.changes), and their
	 * results say parked: true. A member whose role never changed only gets
	 * the refusals: the app does not offer what the role forbids, so those
	 * come from a role change the app had not seen yet.
	 */
	private static function parkRoleRefusals($ctx, $p, $pushId, $clientId, $changes, $results) {
		if ($p['role_changed_at'] === null) {
			return $results;
		}
		$park = array();
		foreach ($results as $i => $r) {
			if ($r['status'] === 'forbidden' && in_array($r['reason'], self::ROLE_REASONS, true)) {
				$park[] = $changes[$i];
				$results[$i]['parked'] = true;
			}
		}
		if (empty($park)) {
			return $results;
		}
		$ctx->db->q(
			"INSERT INTO strabomicro.micro_parked_pushes (project_id, user_pkey, payload) VALUES ($1, $2, $3::json)",
			array($p['id'], $ctx->me, MsHttp::encode(array(
				'reason' => 'role_changed', 'role' => $p['role'],
				'pushId' => $pushId, 'clientId' => $clientId, 'changes' => $park))));
		return $results;
	}

	/**
	 * Apply changes as $ctx->me without HTTP (the conversion, MsConvert).
	 * Caller holds the transaction and the project lock and sets
	 * $ctx->project (id, strabo_id, role, head_seq) and $ctx->pushId.
	 * Changes are decoded JSON objects, as a push body would carry them.
	 */
	public static function applyChanges($ctx, $pid, $changes) {
		return self::applyAll($ctx, $pid, $changes);
	}

	/**
	 * Apply every change; returns results in the same order. A create whose
	 * parent (or nesting parent) is a create in this push that was not
	 * accepted is rejected as parent_rejected, and so on down the tree; a
	 * parent never fails because of a child.
	 */
	private static function applyAll($ctx, $pid, $changes) {
		$results = array();
		$failed = array();
		foreach ($changes as $c) {
			if (MsHttp::prop($c, 'op') === 'create') {
				$cause = null;
				foreach (self::createDependencies($c) as $dep) {
					if (isset($failed[$dep])) {
						$cause = $dep;
						break;
					}
				}
				if ($cause !== null) {
					$failed[self::changeKey($c)] = true;
					$results[] = array('type' => MsHttp::prop($c, 'type'), 'id' => MsHttp::prop($c, 'id'),
						'status' => 'invalid', 'reason' => 'parent_rejected', 'cause' => $cause,
						'message' => "its parent $cause was not accepted");
					continue;
				}
				$r = self::applyOneSafely($ctx, $pid, $c);
				if ($r['status'] !== 'accepted') {
					$failed[self::changeKey($c)] = true;
				}
				$results[] = $r;
				continue;
			}
			$results[] = self::applyOneSafely($ctx, $pid, $c);
		}
		return $results;
	}

	private static function changeKey($c) {
		return MsHttp::prop($c, 'type') . ':' . MsHttp::prop($c, 'id');
	}

	/** Keys a create depends on: its parent, and a micrograph's parentID. */
	private static function createDependencies($c) {
		$deps = array(MsHttp::prop($c, 'parentType') . ':' . MsHttp::prop($c, 'parentId'));
		if (MsHttp::prop($c, 'type') === 'micrograph') {
			$nest = MsHttp::prop(MsHttp::prop($c, 'body'), 'parentID');
			if (is_string($nest) && $nest !== '') {
				$deps[] = 'micrograph:' . $nest;
			}
		}
		return $deps;
	}

	/** One change inside its own savepoint; rule violations become results. */
	private static function applyOneSafely($ctx, $pid, $c) {
		$db = $ctx->db;
		$type = MsHttp::prop($c, 'type');
		$id = MsHttp::prop($c, 'id');
		$base = array('type' => is_string($type) ? $type : null, 'id' => is_string($id) ? $id : null);
		$db->savepoint('ms_change');
		try {
			$result = self::applyOne($ctx, $pid, $c);
			$db->release('ms_change');
			return $result;
		} catch (MsInvalid $e) {
			$db->rollbackTo('ms_change');
			$db->release('ms_change');
			return $base + array('status' => 'invalid', 'reason' => $e->reason, 'message' => $e->getMessage());
		} catch (MsParentDeleted $e) {
			$db->rollbackTo('ms_change');
			$db->release('ms_change');
			return $base + self::deletedFields($db, $e->row) + array(
				'reason' => 'parent_deleted',
				'parent' => array('type' => $e->row['entity_type'], 'id' => $e->row['entity_id']));
		}
	}

	private static function applyOne($ctx, $pid, $c) {
		if (!is_object($c)) {
			throw new MsInvalid('schema', 'each change must be a JSON object');
		}
		$op = MsHttp::prop($c, 'op');
		$type = MsHttp::prop($c, 'type');
		$id = MsHttp::prop($c, 'id');
		if (!MsModel::isType($type)) {
			throw new MsInvalid('schema', 'unknown entity type');
		}
		if (!MsModel::isId($id)) {
			throw new MsInvalid('schema', 'bad entity id');
		}
		if (!MsStore::canWrite($ctx->project['role'])) {
			return self::forbidden($type, $id, 'viewer');
		}
		switch ($op) {
			case 'create':  return self::create($ctx, $pid, $type, $id, $c);
			case 'update':  return self::update($ctx, $pid, $type, $id, $c);
			case 'delete':  return self::delete($ctx, $pid, $type, $id, $c);
			case 'restore': return self::restore($ctx, $pid, $type, $id, $c);
		}
		throw new MsInvalid('schema', 'op must be create, update, delete, or restore');
	}

	// ------------------------------------------------------------------
	// Operations
	// ------------------------------------------------------------------

	private static function create($ctx, $pid, $type, $id, $c) {
		$db = $ctx->db;
		$role = $ctx->project['role'];
		$ptype = MsHttp::prop($c, 'parentType');
		$ppid = MsHttp::prop($c, 'parentId');

		if ($type === 'project') {
			if ($ptype !== null || $ppid !== null) {
				throw new MsInvalid('schema', 'the project entity has no parent');
			}
			if ($id !== $ctx->project['strabo_id']) {
				throw new MsInvalid('schema', 'the project entity id must equal the project straboId');
			}
			if ($role === 'contributor') {
				return self::forbidden($type, $id, 'settings');
			}
		} else {
			if ($ptype !== MsModel::$PARENT[$type]) {
				throw new MsInvalid('schema', "the parent of a $type must be a " . MsModel::$PARENT[$type]);
			}
			if (!MsModel::isId($ppid)) {
				throw new MsInvalid('schema', 'bad parentId');
			}
		}

		$existing = MsStore::entity($db, $pid, $type, $id);
		if ($existing !== null) {
			throw new MsInvalid('exists', MsStore::isLive($existing)
				? "this $type already exists"
				: "this $type was deleted; restore it instead of creating it again");
		}
		if ($type !== 'project') {
			self::requireLiveParent($db, $pid, $ptype, $ppid);
		}

		$body = MsModel::cleanBody($type, $id, MsHttp::prop($c, 'body'), $ppid);
		if ($type === 'micrograph') {
			self::checkNesting($db, $pid, $id, $body, $ppid);
		}
		$order = array();
		if (MsHttp::prop($c, 'childOrder') !== null) {
			$order = MsModel::checkChildOrder($type, MsHttp::prop($c, 'childOrder'));
		}

		$db->q(
			"INSERT INTO strabomicro.micro_entities
			   (project_id, entity_type, entity_id, parent_type, parent_id, body, child_order, version, created_by, updated_by)
			 VALUES ($1, $2, $3, $4, $5, $6::json, $7::json, 1, $8, $8)",
			array($pid, $type, $id, $ptype, $ppid, MsHttp::encode($body),
			      empty($order) ? null : MsHttp::encode($order), $ctx->me));
		$after = array('parentType' => $ptype, 'parentId' => $ppid, 'body' => $body,
			'childOrder' => empty($order) ? new stdClass() : $order, 'refs' => new stdClass());
		$seq = MsStore::logChange($db, $ctx, $pid, $type, $id, 'create', 1, null, null, $after);
		return self::accepted($type, $id, 1, $seq);
	}

	private static function update($ctx, $pid, $type, $id, $c) {
		$db = $ctx->db;
		$row = MsStore::entity($db, $pid, $type, $id);
		if ($row === null) {
			throw new MsInvalid('not_found', "this $type does not exist");
		}
		if (!MsStore::isLive($row)) {
			return array('type' => $type, 'id' => $id) + self::deletedFields($db, $row);
		}
		$fields = MsHttp::prop($c, 'fields');
		$orderIn = MsHttp::prop($c, 'childOrder');
		$isMove = is_object($c) && (property_exists($c, 'parentId') || property_exists($c, 'parentType'));
		// A childOrder-only update is not an edit of the entity (v3 §4.3): a
		// Contributor adding a spot to someone else's micrograph also sends
		// that micrograph's new child order, which must not be refused
		if ($fields !== null || $isMove) {
			$denied = self::writeDenied($ctx, $type, $row);
			if ($denied !== null) {
				return self::forbidden($type, $id, $denied);
			}
		}
		if ($fields === null && $orderIn === null && !$isMove) {
			throw new MsInvalid('schema', 'an update needs fields, childOrder, or a new parent');
		}
		$versioned = $fields !== null || $isMove;
		if ($versioned) {
			$conflict = self::checkBase($db, $type, $id, $row, $c);
			if ($conflict !== null) {
				return $conflict;
			}
		}

		$body = $row['body'];
		$paths = array();
		if ($fields !== null) {
			list($body, $fieldPaths) = MsModel::applyFields($type, $body, $fields);
			$paths = array_merge($paths, $fieldPaths);
		}

		$ppid = $row['parent_id'];
		if ($isMove) {
			if ($type === 'project') {
				throw new MsInvalid('schema', 'the project entity has no parent');
			}
			if ($type === 'point_count') {
				throw new MsInvalid('schema', 'a point count session belongs to its micrograph and cannot move');
			}
			$newType = MsHttp::prop($c, 'parentType', MsModel::$PARENT[$type]);
			$newId = MsHttp::prop($c, 'parentId');
			if ($newType !== MsModel::$PARENT[$type]) {
				throw new MsInvalid('schema', "the parent of a $type must be a " . MsModel::$PARENT[$type]);
			}
			if (!MsModel::isId($newId)) {
				throw new MsInvalid('schema', 'bad parentId');
			}
			if ($newId !== $ppid) {
				self::requireLiveParent($db, $pid, $newType, $newId);
				if ($type === 'micrograph' && self::hasNestedMicrographs($db, $pid, $id)) {
					throw new MsInvalid('parent_other_sample',
						'a micrograph with micrographs nested under it cannot move to another sample');
				}
				$ppid = $newId;
				$paths[] = 'parentId';
			}
		}
		if ($type === 'micrograph'
			&& ($ppid !== $row['parent_id'] || MsHttp::prop($body, 'parentID') !== MsHttp::prop($row['body'], 'parentID'))) {
			self::checkNesting($db, $pid, $id, $body, $ppid);
		}

		$order = $row['child_order'];
		if ($orderIn !== null) {
			foreach (MsModel::checkChildOrder($type, $orderIn) as $k => $ids) {
				$order[$k] = self::filterLiveChildren($db, $pid, $type, $id, MsModel::$CHILD_KEYS[$type][$k], $ids);
			}
			$paths[] = 'childOrder';
		}

		$version = $versioned ? $row['version'] + 1 : $row['version'];
		$db->q(
			"UPDATE strabomicro.micro_entities
			    SET parent_id = $4, body = $5::json, child_order = $6::json, version = $7,
			        updated_by = $8, updated_at = now()
			  WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3",
			array($pid, $type, $id, $ppid, MsHttp::encode($body),
			      empty($order) ? null : MsHttp::encode($order), $version, $ctx->me));

		$before = MsStore::state($db, $pid, $row);
		$after = $before;
		$after['parentId'] = $ppid;
		$after['body'] = $body;
		$after['childOrder'] = empty($order) ? new stdClass() : $order;
		$seq = MsStore::logChange($db, $ctx, $pid, $type, $id, 'update', $version, $paths, $before, $after);
		return self::accepted($type, $id, $version, $seq);
	}

	private static function delete($ctx, $pid, $type, $id, $c) {
		$db = $ctx->db;
		$row = MsStore::entity($db, $pid, $type, $id);
		if ($row === null) {
			throw new MsInvalid('not_found', "this $type does not exist");
		}
		if (!MsStore::isLive($row)) {
			return array('type' => $type, 'id' => $id) + self::deletedFields($db, $row);
		}
		if ($type === 'project') {
			throw new MsInvalid('schema', 'the project entity cannot be deleted');
		}
		$denied = self::writeDenied($ctx, $type, $row);
		if ($denied !== null) {
			return self::forbidden($type, $id, $denied);
		}
		$conflict = self::checkBase($db, $type, $id, $row, $c);
		if ($conflict !== null) {
			return $conflict;
		}

		$cascade = self::descendants($db, $pid, $type, $id, null);
		// cascadeVersions: what the client knows beneath the entity ("type:id"
		// => version). Something beneath changed or added since then means the
		// client has not seen it: a conflict, so its pull asks the user instead
		// of the delete taking it away (collaboration spec v3 §4.5)
		$known = MsHttp::prop($c, 'cascadeVersions');
		if ($known !== null) {
			if (!is_object($known)) {
				throw new MsInvalid('schema', 'cascadeVersions must be a JSON object');
			}
			foreach ($cascade as $d) {
				$v = MsHttp::prop($known, MsModel::key($d['entity_type'], $d['entity_id']));
				if ($v !== (int)$d['version']) {
					$out = self::conflictResult($db, $type, $id, $row);
					$out['changedBeneath'] = array('type' => $d['entity_type'], 'id' => $d['entity_id']);
					return $out;
				}
			}
		}
		if ($ctx->project['role'] === 'contributor') {
			foreach ($cascade as $d) {
				if ($d['created_by'] !== $ctx->me) {
					return self::forbidden($type, $id, 'cascade_includes_others');
				}
			}
		}

		$rootKey = MsModel::key($type, $id);
		$rootVersion = null;
		$rootSeq = null;
		foreach (array_merge(array($row), $cascade) as $e) {
			$v = $e['version'] + 1;
			$db->q(
				"UPDATE strabomicro.micro_entities
				    SET deleted_at = now(), deleted_by = $4, deleted_root = $5, version = $6,
				        updated_by = $4, updated_at = now()
				  WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3",
				array($pid, $e['entity_type'], $e['entity_id'], $ctx->me, $rootKey, $v));
			$seq = MsStore::logChange($db, $ctx, $pid, $e['entity_type'], $e['entity_id'], 'delete', $v, null,
				MsStore::state($db, $pid, $e), null);
			if ($rootSeq === null) {
				$rootVersion = $v;
				$rootSeq = $seq;
			}
		}
		$out = self::accepted($type, $id, $rootVersion, $rootSeq);
		$out['cascaded'] = count($cascade);
		return $out;
	}

	private static function restore($ctx, $pid, $type, $id, $c) {
		$db = $ctx->db;
		$row = MsStore::entity($db, $pid, $type, $id);
		if ($row === null) {
			throw new MsInvalid('not_found', "this $type does not exist");
		}
		if (MsStore::isLive($row)) {
			throw new MsInvalid('not_deleted', "this $type is not deleted");
		}
		// Contributors restore only what they deleted themselves and created (17w)
		$mineOnly = !in_array($ctx->project['role'], array('owner', 'editor'), true);
		if ($mineOnly && ($ctx->project['role'] !== 'contributor'
			|| $row['created_by'] !== $ctx->me || (int)$row['deleted_by'] !== $ctx->me)) {
			return self::forbidden($type, $id, 'editor_required');
		}
		if ($row['parent_type'] !== null) {
			self::requireLiveParent($db, $pid, $row['parent_type'], $row['parent_id']);
		}
		if ($type === 'micrograph') {
			$nest = MsHttp::prop($row['body'], 'parentID');
			if (is_string($nest) && $nest !== '') {
				$par = MsStore::entity($db, $pid, 'micrograph', $nest);
				if ($par !== null && !MsStore::isLive($par)) {
					throw new MsParentDeleted($par);
				}
			}
		}

		$list = array($row);
		if (MsHttp::prop($c, 'cascade') === true) {
			$list = array_merge($list, self::descendants($db, $pid, $type, $id, $row['deleted_root']));
		}
		if ($mineOnly) {
			foreach ($list as $e) {
				if ($e['created_by'] === null || (int)$e['created_by'] !== $ctx->me) {
					return self::forbidden($type, $id, 'cascade_includes_others');
				}
			}
		}
		$rootVersion = null;
		$rootSeq = null;
		foreach ($list as $e) {
			$v = $e['version'] + 1;
			$db->q(
				"UPDATE strabomicro.micro_entities
				    SET deleted_at = NULL, deleted_by = NULL, deleted_root = NULL, version = $4,
				        updated_by = $5, updated_at = now()
				  WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3",
				array($pid, $e['entity_type'], $e['entity_id'], $v, $ctx->me));
			$seq = MsStore::logChange($db, $ctx, $pid, $e['entity_type'], $e['entity_id'], 'restore', $v, null,
				null, MsStore::state($db, $pid, $e));
			if ($rootSeq === null) {
				$rootVersion = $v;
				$rootSeq = $seq;
			}
		}
		$out = self::accepted($type, $id, $rootVersion, $rootSeq);
		$out['cascaded'] = count($list) - 1;
		return $out;
	}

	// ------------------------------------------------------------------
	// Rules
	// ------------------------------------------------------------------

	/** Reason code when my role cannot edit or delete this entity, else null. */
	private static function writeDenied($ctx, $type, $row) {
		$role = $ctx->project['role'];
		if ($role === 'owner' || $role === 'editor') {
			return null;
		}
		if ($type === 'project') {
			return 'settings';
		}
		return $row['created_by'] === $ctx->me ? null : 'contributor_not_creator';
	}

	/** Conflict result when baseVersion is stale, null when it matches. */
	private static function checkBase($db, $type, $id, $row, $c) {
		$base = MsHttp::prop($c, 'baseVersion');
		if (!is_int($base)) {
			throw new MsInvalid('schema', 'baseVersion (integer) is required');
		}
		if ($base === $row['version']) {
			return null;
		}
		return self::conflictResult($db, $type, $id, $row);
	}

	/** A conflict result with the entity's current state. */
	private static function conflictResult($db, $type, $id, $row) {
		$users = MsStore::users($db, array($row['updated_by']));
		return array('type' => $type, 'id' => $id, 'status' => 'conflict', 'current' => array(
			'version'    => $row['version'],
			'parentType' => $row['parent_type'],
			'parentId'   => $row['parent_id'],
			'body'       => $row['body'],
			'childOrder' => empty($row['child_order']) ? new stdClass() : $row['child_order'],
			'updatedBy'  => MsStore::user($users, $row['updated_by']),
			'updatedAt'  => $row['updated_at'],
		));
	}

	private static function requireLiveParent($db, $pid, $ptype, $ppid) {
		$par = MsStore::entity($db, $pid, $ptype, $ppid);
		if ($par === null) {
			throw new MsInvalid('parent_missing', "parent $ptype $ppid does not exist");
		}
		if (!MsStore::isLive($par)) {
			throw new MsParentDeleted($par);
		}
		return $par;
	}

	/**
	 * A micrograph's parentID (nesting) must name a live micrograph in the
	 * same sample and must not create a loop.
	 */
	private static function checkNesting($db, $pid, $id, $body, $sampleId) {
		$nest = MsHttp::prop($body, 'parentID');
		if ($nest === null || $nest === '') {
			return;
		}
		if (!MsModel::isId($nest)) {
			throw new MsInvalid('schema', 'parentID must be a micrograph id');
		}
		if ($nest === $id) {
			throw new MsInvalid('cycle', 'a micrograph cannot be nested under itself');
		}
		$par = MsStore::entity($db, $pid, 'micrograph', $nest);
		if ($par === null) {
			return; // dangling reference that never existed: preserved as in legacy data
		}
		if (!MsStore::isLive($par)) {
			throw new MsParentDeleted($par);
		}
		if ($par['parent_id'] !== $sampleId) {
			throw new MsInvalid('parent_other_sample', 'parentID names a micrograph in another sample');
		}
		$cur = MsHttp::prop($par['body'], 'parentID');
		for ($steps = 0; is_string($cur) && $cur !== '' && $steps < 10000; $steps++) {
			if ($cur === $id) {
				throw new MsInvalid('cycle', 'this parentID would nest the micrograph under its own descendant');
			}
			$up = MsStore::entity($db, $pid, 'micrograph', $cur);
			$cur = $up === null ? null : MsHttp::prop($up['body'], 'parentID');
		}
	}

	private static function hasNestedMicrographs($db, $pid, $id) {
		return $db->val(
			"SELECT 1 FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND entity_type = 'micrograph' AND body->>'parentID' = $2
			    AND deleted_at IS NULL LIMIT 1",
			array($pid, $id)) !== null;
	}

	/** Keep only ids that are live children of the given type, in order. */
	private static function filterLiveChildren($db, $pid, $type, $id, $childType, $ids) {
		$live = array();
		foreach ($db->rows(
			"SELECT entity_id FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND parent_type = $2 AND parent_id = $3 AND entity_type = $4
			    AND deleted_at IS NULL",
			array($pid, $type, $id, $childType)) as $r) {
			$live[$r['entity_id']] = true;
		}
		$out = array();
		foreach ($ids as $cid) {
			if (isset($live[$cid])) {
				$out[] = $cid;
			}
		}
		return $out;
	}

	/**
	 * Descendants of an entity, parents before children: structural
	 * children plus micrographs nested by parentID. $deletedRoot null: live
	 * ones; otherwise tombstones from that delete event.
	 */
	private static function descendants($db, $pid, $type, $id, $deletedRoot) {
		$cond = $deletedRoot === null
			? 'deleted_at IS NULL'
			: 'deleted_at IS NOT NULL AND deleted_root = $4';
		$out = array();
		$seen = array(MsModel::key($type, $id) => true);
		$queue = array(array($type, $id));
		while (!empty($queue)) {
			list($t, $i) = array_shift($queue);
			$params = array($pid, $t, $i);
			if ($deletedRoot !== null) {
				$params[] = $deletedRoot;
			}
			$rows = $db->rows(
				"SELECT " . self::ENTITY_COLUMNS . " FROM strabomicro.micro_entities
				  WHERE project_id = $1 AND parent_type = $2 AND parent_id = $3 AND $cond
				  ORDER BY created_at, entity_id",
				$params);
			if ($t === 'micrograph') {
				$rows = array_merge($rows, $db->rows(
					"SELECT " . self::ENTITY_COLUMNS . " FROM strabomicro.micro_entities
					  WHERE project_id = $1 AND entity_type = $2 AND body->>'parentID' = $3 AND $cond
					  ORDER BY created_at, entity_id",
					$params));
			}
			foreach ($rows as $r) {
				$k = MsModel::key($r['entity_type'], $r['entity_id']);
				if (isset($seen[$k])) {
					continue;
				}
				$seen[$k] = true;
				$out[] = MsStore::decodeRow($r);
				$queue[] = array($r['entity_type'], $r['entity_id']);
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Result shapes
	// ------------------------------------------------------------------

	private static function accepted($type, $id, $version, $seq) {
		return array('type' => $type, 'id' => $id, 'status' => 'accepted', 'version' => $version, 'seq' => $seq);
	}

	private static function forbidden($type, $id, $reason) {
		return array('type' => $type, 'id' => $id, 'status' => 'forbidden', 'reason' => $reason);
	}

	private static function deletedFields($db, $row) {
		$users = MsStore::users($db, array($row['deleted_by']));
		return array('status' => 'deleted', 'deletedBy' => MsStore::user($users, $row['deleted_by']),
			'deletedAt' => $row['deleted_at']);
	}

	// ------------------------------------------------------------------
	// GET projects/{pid}/changes?since=&limit=
	// ------------------------------------------------------------------

	public static function changes($ctx, $pid) {
		$db = $ctx->db;
		$since = MsHttp::queryInt('since', 0, 0, PHP_INT_MAX);
		$limit = MsHttp::queryInt('limit', 1000, 1, 1000);
		$db->beginSnapshot();
		$p = MsStore::project($db, $pid, $ctx->me);
		$rows = $db->rows(
			"SELECT seq, push_id, entity_type, entity_id, op, version,
			        array_to_json(changed_paths)::text AS changed_paths, after::text AS after, user_pkey,
			        " . MsDb::iso('at') . " AS at
			   FROM strabomicro.micro_changes
			  WHERE project_id = $1 AND seq > $2
			  ORDER BY seq LIMIT $3",
			array($pid, $since, $limit + 1));
		$db->commit();
		$more = count($rows) > $limit;
		if ($more) {
			array_pop($rows);
		}
		MsHttp::json(200, array(
			'headSeq' => $more ? (int)$rows[count($rows) - 1]['seq'] : $p['head_seq'],
			'more'    => $more,
			'changes' => self::formatChanges($db, $rows, false),
		));
	}

	// ------------------------------------------------------------------
	// GET projects/{pid}/history?entity=<type>:<id> | ?since=&user=
	//                           | ?brief=1&before=  (the activity panel, 17v)
	// ------------------------------------------------------------------

	/** Changed paths that are bookkeeping, not someone's edit (hidden in brief history). */
	const NOISE_PATHS = array('childOrder', 'modifiedTimestamp', 'refs.tiles', 'refs.tiles_affine', 'refs.thumbnail');
	/** Also bookkeeping on these types: the app stamps date when it saves. */
	const NOISE_DATE_TYPES = array('project', 'dataset');

	public static function history($ctx, $pid) {
		$db = $ctx->db;
		$p = MsStore::project($db, $pid, $ctx->me);
		if (isset($_GET['brief']) && $_GET['brief'] === '1') {
			self::briefHistory($ctx, $pid, $p);
			return;
		}
		$limit = MsHttp::queryInt('limit', 200, 1, 1000);
		$where = 'project_id = $1';
		$params = array($pid);
		if (isset($_GET['entity']) && $_GET['entity'] !== '') {
			$parts = explode(':', (string)$_GET['entity'], 2);
			if (count($parts) !== 2 || !MsModel::isType($parts[0]) || !MsModel::isId($parts[1])) {
				throw new MsHttpError(400, 'bad_request', 'entity must be <type>:<id>');
			}
			$params[] = $parts[0];
			$params[] = $parts[1];
			$where .= ' AND entity_type = $2 AND entity_id = $3';
		} else {
			$params[] = MsHttp::queryInt('since', 0, 0, PHP_INT_MAX);
			$where .= ' AND seq > $2';
			if (isset($_GET['user']) && $_GET['user'] !== '') {
				$params[] = MsHttp::queryInt('user', 0, 0, PHP_INT_MAX);
				$where .= ' AND user_pkey = $3';
			}
		}
		$params[] = $limit + 1;
		$rows = $db->rows(
			"SELECT seq, push_id, entity_type, entity_id, op, version,
			        array_to_json(changed_paths)::text AS changed_paths, before::text AS before,
			        after::text AS after, user_pkey, " . MsDb::iso('at') . " AS at
			   FROM strabomicro.micro_changes
			  WHERE $where ORDER BY seq LIMIT $" . count($params),
			$params);
		$more = count($rows) > $limit;
		if ($more) {
			array_pop($rows);
		}
		MsHttp::json(200, array('headSeq' => $p['head_seq'], 'more' => $more,
			'changes' => self::formatChanges($db, $rows, true)));
	}

	/**
	 * Newest first, without bodies, and without bookkeeping updates (child
	 * order, timestamps, derived files): what the activity panel and the
	 * website history list. Pages back with before=<seq of the last row>.
	 * Each row: name (of the entity, from its body), parent (after, else
	 * before), movedFrom (the old parent of a move), the changed paths that
	 * are edits, who and when, onBehalfOf (an accepted parked change, 17y),
	 * and here (with clientId=: my own change from that computer).
	 */
	private static function briefHistory($ctx, $pid, $p) {
		$db = $ctx->db;
		$limit = MsHttp::queryInt('limit', 200, 1, 500);
		$before = MsHttp::queryInt('before', 0, 0, PHP_INT_MAX);
		$clientId = isset($_GET['clientId']) ? substr((string)$_GET['clientId'], 0, 200) : '';
		$noise = '{' . implode(',', self::NOISE_PATHS) . '}';
		$noiseDated = '{' . implode(',', array_merge(self::NOISE_PATHS, array('date'))) . '}';
		// here: my own change from this computer (the activity poll's rule), so it is in my copy already
		$rows = $db->rows(
			"SELECT c.seq, c.push_id, c.entity_type, c.entity_id, c.op, array_to_json(c.changed_paths)::text AS changed_paths,
			        COALESCE(c.after->'body'->>'name', c.after->'body'->>'sampleID',
			                 c.before->'body'->>'name', c.before->'body'->>'sampleID') AS name,
			        COALESCE(c.after->>'parentType', c.before->>'parentType') AS parent_type,
			        COALESCE(c.after->>'parentId', c.before->>'parentId') AS parent_id,
			        CASE WHEN c.op = 'update' AND c.after->>'parentId' IS DISTINCT FROM c.before->>'parentId'
			             THEN c.before->>'parentId' END AS moved_from,
			        c.user_pkey, c.on_behalf_of, " . MsDb::iso('c.at') . " AS at,
			        (c.user_pkey = $6 AND $7 <> '' AND (c.push_id IS NULL OR COALESCE(ps.client_id, '') = $7)) AS here
			   FROM strabomicro.micro_changes c
			   LEFT JOIN strabomicro.micro_pushes ps ON ps.push_id = c.push_id
			  WHERE c.project_id = $1 AND ($2::bigint = 0 OR c.seq < $2::bigint)
			    AND NOT (c.op = 'update' AND c.changed_paths IS NOT NULL AND c.changed_paths <@
			             (CASE WHEN c.entity_type IN ('project', 'dataset') THEN $3::text[] ELSE $4::text[] END))
			  ORDER BY c.seq DESC LIMIT $5",
			array($pid, $before, $noiseDated, $noise, $limit + 1, $ctx->me, $clientId));
		$more = count($rows) > $limit;
		if ($more) {
			array_pop($rows);
		}
		$users = MsStore::users($db, array_merge(array_column($rows, 'user_pkey'), array_column($rows, 'on_behalf_of')));
		$out = array();
		foreach ($rows as $r) {
			$paths = $r['changed_paths'] === null ? null : json_decode($r['changed_paths'], true);
			if (is_array($paths)) {
				$hide = in_array($r['entity_type'], self::NOISE_DATE_TYPES, true)
					? array_merge(self::NOISE_PATHS, array('date')) : self::NOISE_PATHS;
				$paths = array_values(array_diff($paths, $hide));
			}
			$out[] = array(
				'seq'          => (int)$r['seq'],
				'pushId'       => $r['push_id'],
				'type'         => $r['entity_type'],
				'id'           => $r['entity_id'],
				'op'           => $r['op'],
				'name'         => $r['name'],
				'parentType'   => $r['parent_type'],
				'parentId'     => $r['parent_id'],
				'movedFrom'    => $r['moved_from'],
				'changedPaths' => $paths,
				'user'         => MsStore::user($users, $r['user_pkey']),
				'onBehalfOf'   => $r['on_behalf_of'] === null ? null : MsStore::user($users, $r['on_behalf_of']),
				'at'           => $r['at'],
				'here'         => $r['here'] === 't',
			);
		}
		MsHttp::json(200, array('headSeq' => $p['head_seq'], 'more' => $more, 'changes' => $out));
	}

	private static function formatChanges($db, $rows, $withBefore) {
		$users = MsStore::users($db, array_column($rows, 'user_pkey'));
		$out = array();
		foreach ($rows as $r) {
			$after = $r['after'] === null ? null : json_decode($r['after']);
			$entry = array(
				'seq'          => (int)$r['seq'],
				'pushId'       => $r['push_id'],
				'type'         => $r['entity_type'],
				'id'           => $r['entity_id'],
				'op'           => $r['op'],
				'version'      => (int)$r['version'],
				'parentType'   => MsHttp::prop($after, 'parentType'),
				'parentId'     => MsHttp::prop($after, 'parentId'),
				'body'         => MsHttp::prop($after, 'body'),
				'childOrder'   => MsHttp::prop($after, 'childOrder'),
				'refs'         => MsHttp::prop($after, 'refs'),
				'changedPaths' => $r['changed_paths'] === null ? null : json_decode($r['changed_paths']),
				'user'         => MsStore::user($users, $r['user_pkey']),
				'at'           => $r['at'],
			);
			if ($withBefore) {
				$entry['before'] = $r['before'] === null ? null : json_decode($r['before']);
				$entry['after'] = $after;
			}
			$out[] = $entry;
		}
		return $out;
	}
}
