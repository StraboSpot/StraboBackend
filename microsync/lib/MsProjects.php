<?php
/**
 * File: MsProjects.php
 * Description: /microsync/v1/projects endpoints: list, create (turn sync
 *              on), ready, metadata, snapshot.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsProjects {

	/** GET projects: synced projects where I am an active member. */
	public static function listMine($ctx) {
		$db = $ctx->db;
		$rows = $db->rows(
			"SELECT p.id, p.strabo_id, COALESCE(e.body->>'name', p.name) AS name, m.role,
			        p.head_seq, p.sync_state,
			        " . MsDb::iso("COALESCE((SELECT c.at FROM strabomicro.micro_changes c
			                                 WHERE c.project_id = p.id ORDER BY c.seq DESC LIMIT 1),
			                                p.uploaddate)") . " AS updated_at,
			        (SELECT o.user_pkey FROM strabomicro.micro_members o
			          WHERE o.project_id = p.id AND o.role = 'owner' AND o.state = 'active') AS owner_pkey
			   FROM strabomicro.micro_members m
			   JOIN strabomicro.micro_projectmetadata p ON p.id = m.project_id
			   LEFT JOIN strabomicro.micro_entities e
			          ON e.project_id = p.id AND e.entity_type = 'project' AND e.entity_id = p.strabo_id
			  WHERE m.user_pkey = $1 AND m.state = 'active' AND p.sync_format = 'entity'
			  ORDER BY p.id",
			array($ctx->me));
		$owners = MsStore::users($db, array_column($rows, 'owner_pkey'), true);
		$out = array();
		foreach ($rows as $r) {
			$out[] = array(
				'pid'       => (int)$r['id'],
				'straboId'  => $r['strabo_id'],
				'name'      => $r['name'],
				'role'      => $r['role'],
				'owner'     => MsStore::user($owners, $r['owner_pkey']),
				'headSeq'   => (int)$r['head_seq'],
				'syncState' => $r['sync_state'],
				'updatedAt' => $r['updated_at'],
			);
		}
		MsHttp::json(200, $out);
	}

	/**
	 * POST projects {straboId, name}: create the server copy of a local
	 * project (sync_state initializing) with me as owner. 409 when I already
	 * own a project with this straboId (legacy upload or synced).
	 */
	public static function create($ctx) {
		$db = $ctx->db;
		$in = MsHttp::readJson(65536);
		$straboId = MsHttp::prop($in, 'straboId');
		$name = MsHttp::prop($in, 'name', '');
		if (!MsModel::isId($straboId)) {
			throw new MsHttpError(400, 'bad_request', 'straboId is required');
		}
		if (!is_string($name) || strlen($name) > 1000) {
			throw new MsHttpError(400, 'bad_request', 'name must be a string');
		}

		$db->begin();
		// Serialize creates of the same (user, straboId) so two clients cannot both insert.
		$db->q("SELECT pg_advisory_xact_lock(hashtext('microsync-create'), hashtext($1))",
			array($ctx->me . ':' . $straboId));
		$existing = $db->row(
			"SELECT id, sync_format FROM strabomicro.micro_projectmetadata
			  WHERE userpkey = $1 AND strabo_id = $2 ORDER BY id LIMIT 1",
			array($ctx->me, $straboId));
		if ($existing !== null) {
			$db->rollback();
			throw new MsHttpError(409, 'exists', 'You already have a project with this id on the server',
				array('pid' => (int)$existing['id'], 'syncFormat' => $existing['sync_format']));
		}
		$pid = (int)$db->val(
			"INSERT INTO strabomicro.micro_projectmetadata
			   (strabo_id, userpkey, name, ispublic, sync_format, sync_state)
			 VALUES ($1, $2, $3, false, 'entity', 'initializing') RETURNING id",
			array($straboId, $ctx->me, $name));
		$db->q(
			"INSERT INTO strabomicro.micro_members (project_id, user_pkey, role, state, invited_by, responded_at)
			 VALUES ($1, $2, 'owner', 'active', $2, now())",
			array($pid, $ctx->me));
		$db->commit();

		MsHttp::json(201, array('pid' => $pid, 'straboId' => $straboId, 'syncState' => 'initializing', 'headSeq' => 0));
	}

	/** POST projects/{pid}/ready: the initial upload has finished. Owner only. */
	public static function ready($ctx, $pid) {
		$db = $ctx->db;
		$p = MsStore::project($db, $pid, $ctx->me);
		MsStore::requireRole($p, array('owner'));
		$hasProjectEntity = $db->val(
			"SELECT 1 FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND entity_type = 'project' AND entity_id = $2 AND deleted_at IS NULL",
			array($pid, $p['strabo_id']));
		if ($hasProjectEntity === null) {
			throw new MsHttpError(409, 'no_project_entity', 'Push the project entity before marking the project ready');
		}
		$db->q(
			"UPDATE strabomicro.micro_projectmetadata
			    SET sync_state = 'ready', views_dirty_since = COALESCE(views_dirty_since, now())
			  WHERE id = $1",
			array($pid));
		MsHttp::json(200, array('pid' => $pid, 'syncState' => 'ready', 'headSeq' => $p['head_seq']));
	}

	/** GET projects/{pid} */
	public static function get($ctx, $pid) {
		$db = $ctx->db;
		$p = MsStore::project($db, $pid, $ctx->me);
		$members = $db->rows(
			"SELECT user_pkey, role, state FROM strabomicro.micro_members
			  WHERE project_id = $1 AND state IN ('active', 'invited') ORDER BY id",
			array($pid));
		$users = MsStore::users($db, array_column($members, 'user_pkey'), true);
		$list = array();
		foreach ($members as $m) {
			$list[] = array('user' => MsStore::user($users, $m['user_pkey']), 'role' => $m['role'], 'state' => $m['state']);
		}
		$name = $db->val(
			"SELECT body->>'name' FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND entity_type = 'project' AND entity_id = $2",
			array($pid, $p['strabo_id']));
		MsHttp::json(200, array(
			'pid'          => $pid,
			'straboId'     => $p['strabo_id'],
			'name'         => $name !== null ? $name : $p['name'],
			'role'         => $p['role'],
			'headSeq'      => $p['head_seq'],
			'syncState'    => $p['sync_state'],
			'members'      => $list,
			'viewsBuiltAt' => $p['views_built_at'],
		));
	}

	/**
	 * GET projects/{pid}/snapshot: every live entity, blob, and ref as of
	 * headSeq, read in one REPEATABLE READ transaction and streamed. Child
	 * orders are normalized (v3 §4.3). Bodies are passed through as stored.
	 */
	public static function snapshot($ctx, $pid) {
		$db = $ctx->db;
		$db->beginSnapshot();
		$p = MsStore::project($db, $pid, $ctx->me);

		// Pass 1: structure only, to normalize child orders.
		$children = array(); // parentKey => childType => [ids in creation order]
		$stored = array();   // entityKey => stored child_order
		foreach ($db->rows(
			"SELECT entity_type, entity_id, parent_type, parent_id, child_order::text AS child_order
			   FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND deleted_at IS NULL
			  ORDER BY created_at, entity_id",
			array($pid)) as $r) {
			if ($r['parent_type'] !== null) {
				$children[MsModel::key($r['parent_type'], $r['parent_id'])][$r['entity_type']][] = $r['entity_id'];
			}
			if ($r['child_order'] !== null) {
				$stored[MsModel::key($r['entity_type'], $r['entity_id'])] = json_decode($r['child_order'], true);
			}
		}

		header('Content-Type: application/json');
		header('Cache-Control: no-store');
		echo '{"headSeq":' . $p['head_seq'] . ',"entities":[';

		// Pass 2: bodies, parents before children.
		$res = $db->q(
			"SELECT entity_type, entity_id, parent_type, parent_id, body::text AS body, version,
			        created_by, updated_by, " . MsDb::iso('updated_at') . " AS updated_at
			   FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND deleted_at IS NULL
			  ORDER BY array_position(ARRAY['project','dataset','sample','micrograph','spot','point_count','tag','group','preset']::varchar[], entity_type),
			           created_at, entity_id",
			array($pid));
		$first = true;
		while (($r = pg_fetch_assoc($res)) !== false) {
			$key = MsModel::key($r['entity_type'], $r['entity_id']);
			$order = MsModel::normalizeChildOrder(
				$r['entity_type'],
				isset($stored[$key]) ? $stored[$key] : array(),
				isset($children[$key]) ? $children[$key] : array());
			echo ($first ? '' : ',') . '{"type":' . MsHttp::encode($r['entity_type'])
				. ',"id":' . MsHttp::encode($r['entity_id'])
				. ',"parentType":' . MsHttp::encode($r['parent_type'])
				. ',"parentId":' . MsHttp::encode($r['parent_id'])
				. ',"body":' . $r['body']
				. ',"childOrder":' . MsHttp::encode(empty($order) ? new stdClass() : $order)
				. ',"version":' . (int)$r['version']
				. ',"createdBy":' . MsHttp::encode($r['created_by'] === null ? null : (int)$r['created_by'])
				. ',"updatedBy":' . MsHttp::encode($r['updated_by'] === null ? null : (int)$r['updated_by'])
				. ',"updatedAt":' . MsHttp::encode($r['updated_at']) . '}';
			$first = false;
		}
		pg_free_result($res);

		$blobs = array();
		foreach ($db->rows(
			"SELECT sha256, size, kind FROM strabomicro.micro_blobs WHERE project_id = $1 ORDER BY sha256",
			array($pid)) as $b) {
			$blobs[] = array('sha256' => $b['sha256'], 'size' => (int)$b['size'], 'kind' => $b['kind']);
		}
		$refs = array();
		foreach ($db->rows(
			"SELECT entity_type, entity_id, role, sha256 FROM strabomicro.micro_blob_refs
			  WHERE project_id = $1 ORDER BY entity_type, entity_id, role",
			array($pid)) as $f) {
			$refs[] = array('type' => $f['entity_type'], 'id' => $f['entity_id'], 'role' => $f['role'], 'sha256' => $f['sha256']);
		}
		$db->commit();
		echo '],"blobs":' . MsHttp::encode($blobs) . ',"refs":' . MsHttp::encode($refs) . '}';
	}
}
