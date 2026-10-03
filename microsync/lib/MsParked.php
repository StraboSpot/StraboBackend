<?php
/**
 * File: MsParked.php
 * Description: The owner's review of parked pushes (v3 §3.2, 17o, 17x-17aa):
 *              changes a removed member sent after removal, or that a
 *              member's new role refused after a role change. The owner's
 *              app shows each item next to the project today; accepted items
 *              are applied there as the owner's own edits (pushed with
 *              onBehalfOf, MsSync) and the app reports each decision here.
 *
 *   GET  projects/{pid}/parked              pending parked pushes (owner)
 *   POST projects/{pid}/parked/{id}/review  {"decisions": {"type:id": "accepted"|"discarded"}}
 *
 * A parked push stays pending until every item in it is decided; then its
 * status is accepted, discarded, or partial (some of each).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsParked {

	private static function ownerProject($db, $pid, $me) {
		$p = MsStore::project($db, $pid, $me);
		if ($p['role'] !== 'owner') {
			throw new MsHttpError(403, 'forbidden', 'Only the project owner reviews parked changes');
		}
		return $p;
	}

	/** The changes of a parked push and their 'type:id' keys (the item ids). */
	private static function changesOf($payload) {
		$changes = MsHttp::prop($payload, 'changes');
		$out = array();
		foreach (is_array($changes) ? $changes : array() as $c) {
			$type = MsHttp::prop($c, 'type');
			$id = MsHttp::prop($c, 'id');
			if (is_string($type) && is_string($id)) {
				$out[$type . ':' . $id] = $c;
			}
		}
		return $out;
	}

	public static function listPending($ctx, $pid) {
		$db = $ctx->db;
		self::ownerProject($db, $pid, $ctx->me);
		$rows = $db->rows(
			"SELECT id, user_pkey, " . MsDb::iso('parked_at') . " AS parked_at, payload::text AS payload, review::text AS review
			   FROM strabomicro.micro_parked_pushes
			  WHERE project_id = $1 AND status = 'pending'
			  ORDER BY parked_at, id",
			array($pid));
		$users = MsStore::users($db, array_column($rows, 'user_pkey'));
		$out = array();
		foreach ($rows as $r) {
			$payload = json_decode($r['payload']);
			$review = $r['review'] === null ? new stdClass() : json_decode($r['review']);
			$reason = MsHttp::prop($payload, 'reason');
			$out[] = array(
				'id'       => (int)$r['id'],
				'user'     => MsStore::user($users, $r['user_pkey']),
				'parkedAt' => $r['parked_at'],
				// A removed member's push is parked whole; after a role change only what the role refused
				'reason'   => $reason === 'role_changed' ? 'role_changed' : 'removed',
				'role'     => MsHttp::prop($payload, 'role'),
				'changes'  => array_values(self::changesOf($payload)),
				'decided'  => $review,
			);
		}
		MsHttp::json(200, array('parked' => $out));
	}

	public static function review($ctx, $pid, $id) {
		$db = $ctx->db;
		$in = MsHttp::readJson(1048576);
		$decisions = MsHttp::prop($in, 'decisions');
		if (!is_object($decisions)) {
			throw new MsHttpError(400, 'bad_request', 'decisions must be an object of "type:id": "accepted" | "discarded"');
		}
		$db->begin();
		MsStore::lockProject($db, $pid);
		self::ownerProject($db, $pid, $ctx->me);
		$row = $db->row(
			"SELECT id, status, payload::text AS payload, review::text AS review
			   FROM strabomicro.micro_parked_pushes WHERE project_id = $1 AND id = $2",
			array($pid, (int)$id));
		if ($row === null) {
			$db->rollback();
			throw new MsHttpError(404, 'not_found', 'No such parked change');
		}
		if ($row['status'] !== 'pending') {
			$db->rollback();
			throw new MsHttpError(409, 'reviewed', 'These changes were reviewed already');
		}
		$items = self::changesOf(json_decode($row['payload']));
		$review = $row['review'] === null ? array() : json_decode($row['review'], true);
		foreach ($decisions as $key => $d) {
			if (!isset($items[$key]) || !in_array($d, array('accepted', 'discarded'), true)) {
				$db->rollback();
				throw new MsHttpError(400, 'bad_request', "unknown item or decision: $key");
			}
			$review[$key] = $d;
		}
		$left = count(array_diff_key($items, $review));
		$status = 'pending';
		if ($left === 0) {
			$values = array_unique(array_values($review));
			$status = count($values) === 1 ? $values[0] : 'partial';
		}
		$db->q(
			"UPDATE strabomicro.micro_parked_pushes
			    SET review = $3::jsonb, status = $4::varchar,
			        reviewed_by = CASE WHEN $4::varchar = 'pending' THEN NULL ELSE $5::int END,
			        reviewed_at = CASE WHEN $4::varchar = 'pending' THEN NULL ELSE now() END
			  WHERE project_id = $1 AND id = $2",
			array($pid, (int)$id, MsHttp::encode((object)$review), $status, $ctx->me));
		$db->commit();
		MsHttp::json(200, array('status' => $status, 'left' => $left));
	}

	/**
	 * May the caller push a change on behalf of $pkey (17y): the owner, for
	 * someone whose changes were parked in this project. Cached per request.
	 */
	public static function mayActFor($ctx, $pid, $pkey) {
		if ($ctx->project['role'] !== 'owner' || $pkey <= 0) {
			return false;
		}
		if (!isset($ctx->parkedUsers)) {
			$ctx->parkedUsers = array();
			foreach ($ctx->db->rows(
				"SELECT DISTINCT user_pkey FROM strabomicro.micro_parked_pushes WHERE project_id = $1", array($pid)) as $r) {
				$ctx->parkedUsers[(int)$r['user_pkey']] = true;
			}
		}
		return isset($ctx->parkedUsers[$pkey]);
	}
}
