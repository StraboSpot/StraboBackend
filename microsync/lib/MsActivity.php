<?php
/**
 * File: MsActivity.php
 * Description: POST projects/{pid}/activity, the activity and presence poll
 *              (v3 §6.3; every 5 s focused, 60 s away). Upserts the caller's
 *              presence, then answers {"changed": false} when nothing is new
 *              since `since` and the presence list matches `presenceHash`.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsActivity {

	/** Presence older than this is not shown. */
	const PRESENCE_WINDOW = '3 minutes';

	public static function poll($ctx, $pid) {
		$db = $ctx->db;
		$in = MsHttp::readJson(16384);
		$since = MsHttp::prop($in, 'since', 0);
		$clientId = MsHttp::prop($in, 'clientId');
		$viewing = MsHttp::prop($in, 'viewing');
		$state = MsHttp::prop($in, 'state', 'active');
		$hashIn = MsHttp::prop($in, 'presenceHash');
		if (!is_int($since) || $since < 0) {
			throw new MsHttpError(400, 'bad_request', 'since must be a non-negative integer');
		}
		if (!is_string($clientId) || $clientId === '' || strlen($clientId) > 200) {
			throw new MsHttpError(400, 'bad_request', 'clientId is required');
		}
		if ($state !== 'active' && $state !== 'away') {
			throw new MsHttpError(400, 'bad_request', 'state must be active or away');
		}
		$vType = null;
		$vId = null;
		if ($viewing !== null) {
			$vType = MsHttp::prop($viewing, 'type');
			$vId = MsHttp::prop($viewing, 'id');
			if (!MsModel::isType($vType) || !MsModel::isId($vId)) {
				throw new MsHttpError(400, 'bad_request', 'viewing must be {type, id}');
			}
		}

		$p = MsStore::project($db, $pid, $ctx->me);
		$db->q(
			"INSERT INTO strabomicro.micro_presence
			   (project_id, user_pkey, client_id, viewing_type, viewing_id, state, last_seen)
			 VALUES ($1, $2, $3, $4, $5, $6, now())
			 ON CONFLICT (project_id, user_pkey, client_id) DO UPDATE
			   SET viewing_type = EXCLUDED.viewing_type, viewing_id = EXCLUDED.viewing_id,
			       state = EXCLUDED.state, last_seen = now()",
			array($pid, $ctx->me, $clientId, $vType, $vId, $state));

		// Other people and my other machines; one entry per user (most recent client).
		$presence = array();
		$seenUser = array();
		$rows = $db->rows(
			"SELECT user_pkey, viewing_type, viewing_id, state, " . MsDb::iso('last_seen') . " AS last_seen
			   FROM strabomicro.micro_presence
			  WHERE project_id = $1 AND last_seen > now() - $4::interval
			    AND NOT (user_pkey = $2 AND client_id = $3)
			  ORDER BY last_seen DESC",
			array($pid, $ctx->me, $clientId, self::PRESENCE_WINDOW));
		$users = MsStore::users($db, array_column($rows, 'user_pkey'));
		foreach ($rows as $r) {
			if (isset($seenUser[$r['user_pkey']])) {
				continue;
			}
			$seenUser[$r['user_pkey']] = true;
			$presence[] = array(
				'user'     => MsStore::user($users, $r['user_pkey']),
				'viewing'  => $r['viewing_type'] === null ? null : array('type' => $r['viewing_type'], 'id' => $r['viewing_id']),
				'state'    => $r['state'],
				'lastSeen' => $r['last_seen'],
			);
		}
		usort($presence, function ($a, $b) { return $a['user']['pkey'] - $b['user']['pkey']; });
		$hashParts = array();
		foreach ($presence as $e) {
			$hashParts[] = array($e['user']['pkey'], $e['viewing'], $e['state']);
		}
		$hash = md5(json_encode($hashParts));

		if ($p['head_seq'] <= $since && $hashIn === $hash) {
			MsHttp::json(200, array('changed' => false));
			return;
		}

		// Changes since `since`, per user, excluding this client's own pushes
		// and my own ref changes (those carry no pushId).
		$pending = array();
		if ($p['head_seq'] > $since) {
			$counts = $db->rows(
				"SELECT c.user_pkey, count(DISTINCT (c.entity_type, c.entity_id)) AS n
				   FROM strabomicro.micro_changes c
				   LEFT JOIN strabomicro.micro_pushes ps ON ps.push_id = c.push_id
				  WHERE c.project_id = $1 AND c.seq > $2
				    AND NOT (c.user_pkey = $3 AND (c.push_id IS NULL OR COALESCE(ps.client_id, '') = $4))
				  GROUP BY c.user_pkey ORDER BY c.user_pkey",
				array($pid, $since, $ctx->me, $clientId));
			$cu = MsStore::users($db, array_column($counts, 'user_pkey'));
			foreach ($counts as $c) {
				$pending[] = array('user' => MsStore::user($cu, $c['user_pkey']), 'count' => (int)$c['n']);
			}
		}

		$parked = 0;
		if ($p['role'] === 'owner') {
			$parked = (int)$db->val(
				"SELECT count(*) FROM strabomicro.micro_parked_pushes WHERE project_id = $1 AND status = 'pending'",
				array($pid));
		}

		MsHttp::json(200, array(
			'changed'      => true,
			'headSeq'      => $p['head_seq'],
			'pending'      => $pending,
			'presence'     => $presence,
			'presenceHash' => $hash,
			'parkedCount'  => $parked,
		));
	}
}
