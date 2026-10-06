<?php
/**
 * File: MsChat.php
 * Description: Project chat (collaboration spec v3, 17bd-17bi). Every active
 *              member reads and posts, Viewers included; removed members
 *              and deleted projects are refused by MsStore::project like
 *              every other call. Chat is not project data: nothing here
 *              touches the change log, history or .smz files.
 *
 *              POST   projects/{pid}/chat            send
 *                     {clientMsgId, text, refs?} -> {message}
 *                     clientMsgId (uuid) makes a resend land once (the same
 *                     message comes back). text: 1 to 4,000 characters after
 *                     trimming; refs: up to 10 {type: spot|micrograph, id}.
 *                     At most 20 messages a minute per person and project
 *                     (429 slow_down with retryAfter seconds).
 *              GET    projects/{pid}/chat            read, one of:
 *                     ?since=REV   every message added or deleted after
 *                                  rev REV, oldest first (up to limit)
 *                     ?before=ID   the page of messages older than ID
 *                     (neither)    the newest page
 *                     -> {messages, rev, hasMore, lastRead, unread}
 *                     rev = the newest rev in this project (since= from
 *                     here on); hasMore: more after this page (since) or
 *                     older ones (before, newest page).
 *              DELETE projects/{pid}/chat/{id}       delete own message;
 *                     the owner may delete anyone's (body and refs emptied,
 *                     the row stays so every app hears of it)
 *              POST   projects/{pid}/chat/read       {id} -> {lastRead, unread}
 *                     marks everything up to id read (never moves back)
 *
 *              Each send or delete NOTIFYs {"t":"chat","pid","rev"}; a read
 *              NOTIFYs {"t":"chatread","pid","user","id"}, which strabo-live
 *              passes only to that user's own connections.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsChat {

	const MAX_CHARS = 4000;
	const MAX_REFS = 10;
	const PER_MINUTE = 20;
	const PAGE = 100;
	const REF_TYPES = array('spot', 'micrograph');

	public static function send($ctx, $pid) {
		$db = $ctx->db;
		$in = MsHttp::readJson(65536);
		$clientMsgId = MsHttp::prop($in, 'clientMsgId');
		$text = MsHttp::prop($in, 'text');
		$refs = MsHttp::prop($in, 'refs', array());
		if (!MsHttp::isUuid($clientMsgId)) {
			throw new MsHttpError(400, 'bad_request', 'clientMsgId must be a UUID');
		}
		$clientMsgId = strtolower($clientMsgId);
		$body = self::cleanText($text);
		$refs = self::cleanRefs($refs);

		MsStore::project($db, $pid, $ctx->me);

		$db->begin();
		// One send at a time per person and project: the rate count and the
		// resend check see each other's rows
		$db->q('SELECT pg_advisory_xact_lock(hashtext($1))', array("microsync-chat:$pid:" . $ctx->me));
		$existing = $db->row(
			"SELECT " . self::COLUMNS . " FROM strabomicro.micro_chat
			  WHERE project_id = $1 AND author_pkey = $2 AND client_msg_id = $3",
			array($pid, $ctx->me, $clientMsgId));
		if ($existing !== null) {
			$db->commit();
			MsHttp::json(200, array('message' => self::out($existing, MsStore::users($db, array($ctx->me))), 'duplicate' => true));
			return;
		}
		$recent = (int)$db->val(
			"SELECT count(*) FROM strabomicro.micro_chat
			  WHERE project_id = $1 AND author_pkey = $2 AND created_at > now() - interval '1 minute'",
			array($pid, $ctx->me));
		if ($recent >= self::PER_MINUTE) {
			$wait = (int)$db->val(
				"SELECT ceil(extract(epoch FROM (min(created_at) + interval '1 minute' - now())))::int
				   FROM (SELECT created_at FROM strabomicro.micro_chat
				          WHERE project_id = $1 AND author_pkey = $2 AND created_at > now() - interval '1 minute'
				          ORDER BY created_at DESC LIMIT $3) r",
				array($pid, $ctx->me, self::PER_MINUTE));
			$db->rollback();
			throw new MsHttpError(429, 'slow_down', 'Too many messages; wait a moment', array('retryAfter' => max(1, $wait)));
		}
		$row = $db->row(
			"INSERT INTO strabomicro.micro_chat (project_id, author_pkey, client_msg_id, body, refs)
			 VALUES ($1, $2, $3, $4, $5::jsonb)
			 RETURNING " . self::COLUMNS,
			array($pid, $ctx->me, $clientMsgId, $body, json_encode($refs)));
		self::notice($db, $pid, (int)$row['rev']);
		$db->commit();
		MsHttp::json(200, array('message' => self::out($row, MsStore::users($db, array($ctx->me)))));
	}

	public static function read($ctx, $pid) {
		$db = $ctx->db;
		$p = MsStore::project($db, $pid, $ctx->me);
		$limit = MsHttp::queryInt('limit', self::PAGE, 1, self::PAGE);
		$hasSince = isset($_GET['since']) && $_GET['since'] !== '';
		$hasBefore = isset($_GET['before']) && $_GET['before'] !== '';
		if ($hasSince && $hasBefore) {
			throw new MsHttpError(400, 'bad_request', 'Use since or before, not both');
		}
		$db->beginSnapshot();
		if ($hasSince) {
			$since = MsHttp::queryInt('since', 0, 0, PHP_INT_MAX);
			$rows = $db->rows(
				"SELECT " . self::COLUMNS . " FROM strabomicro.micro_chat
				  WHERE project_id = $1 AND rev > $2 ORDER BY rev LIMIT $3",
				array($pid, $since, $limit + 1));
		} else {
			$before = $hasBefore ? MsHttp::queryInt('before', 0, 0, PHP_INT_MAX) : null;
			$rows = $db->rows(
				"SELECT " . self::COLUMNS . " FROM strabomicro.micro_chat
				  WHERE project_id = $1 AND ($2::bigint IS NULL OR id < $2::bigint)
				  ORDER BY id DESC LIMIT $3",
				array($pid, $before, $limit + 1));
		}
		$hasMore = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);
		if (!$hasSince) {
			$rows = array_reverse($rows);
		}
		$rev = (int)$db->val(
			"SELECT COALESCE(max(rev), 0) FROM strabomicro.micro_chat WHERE project_id = $1", array($pid));
		$state = self::readState($db, $pid, $ctx->me);
		$db->commit();

		$users = MsStore::users($db, array_merge(array_column($rows, 'author_pkey'), array_column($rows, 'deleted_by')));
		$out = array();
		foreach ($rows as $r) {
			$out[] = self::out($r, $users);
		}
		MsHttp::json(200, array(
			'messages' => $out,
			'rev'      => $hasSince && $hasMore ? (int)end($rows)['rev'] : $rev,
			'hasMore'  => $hasMore,
			'lastRead' => $state['lastRead'],
			'unread'   => $state['unread'],
			'role'     => $p['role'],
		));
	}

	public static function delete($ctx, $pid, $id) {
		$db = $ctx->db;
		$p = MsStore::project($db, $pid, $ctx->me);
		$id = (int)$id;
		$db->begin();
		$row = $db->row(
			"SELECT author_pkey, deleted_at FROM strabomicro.micro_chat
			  WHERE project_id = $1 AND id = $2 FOR UPDATE",
			array($pid, $id));
		if ($row === null) {
			$db->rollback();
			throw new MsHttpError(404, 'not_found', 'Message not found');
		}
		if ((int)$row['author_pkey'] !== $ctx->me && $p['role'] !== 'owner') {
			$db->rollback();
			throw new MsHttpError(403, 'forbidden', 'Only the author or the project owner can delete a message');
		}
		if ($row['deleted_at'] !== null) {
			$db->commit();
			MsHttp::json(200, array('deleted' => true, 'already' => true));
			return;
		}
		$rev = (int)$db->val(
			"UPDATE strabomicro.micro_chat
			    SET deleted_at = now(), deleted_by = $3, body = '', refs = '[]'::jsonb,
			        rev = nextval('strabomicro.micro_chat_rev_seq')
			  WHERE project_id = $1 AND id = $2
			  RETURNING rev",
			array($pid, $id, $ctx->me));
		self::notice($db, $pid, $rev);
		$db->commit();
		MsHttp::json(200, array('deleted' => true, 'rev' => $rev));
	}

	public static function markRead($ctx, $pid) {
		$db = $ctx->db;
		$in = MsHttp::readJson(1024);
		$id = MsHttp::prop($in, 'id');
		if (!is_int($id) || $id < 0) {
			throw new MsHttpError(400, 'bad_request', 'id must be a non-negative integer');
		}
		MsStore::project($db, $pid, $ctx->me);
		$db->begin();
		// Never past the newest message, never backwards
		$newest = (int)$db->val(
			"SELECT COALESCE(max(id), 0) FROM strabomicro.micro_chat WHERE project_id = $1", array($pid));
		$id = min($id, $newest);
		$last = (int)$db->val(
			"INSERT INTO strabomicro.micro_chat_reads (project_id, user_pkey, last_read_id, updated_at)
			 VALUES ($1, $2, $3, now())
			 ON CONFLICT (project_id, user_pkey) DO UPDATE
			   SET last_read_id = GREATEST(strabomicro.micro_chat_reads.last_read_id, EXCLUDED.last_read_id),
			       updated_at = now()
			 RETURNING last_read_id",
			array($pid, $ctx->me, $id));
		MsLive::chatRead($db, $pid, $ctx->me, $last);
		$state = self::readState($db, $pid, $ctx->me);
		$db->commit();
		MsHttp::json(200, $state);
	}

	// -----------------------------------------------------------------------

	const COLUMNS = "id, author_pkey, client_msg_id, body, refs, deleted_by, rev,
		to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS created_at,
		to_char(deleted_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS deleted_at";

	/** Unread = messages after my read marker by other people, not deleted. */
	private static function readState($db, $pid, $me) {
		$last = (int)$db->val(
			"SELECT COALESCE((SELECT last_read_id FROM strabomicro.micro_chat_reads
			                   WHERE project_id = $1 AND user_pkey = $2), 0)",
			array($pid, $me));
		$unread = (int)$db->val(
			"SELECT count(*) FROM strabomicro.micro_chat
			  WHERE project_id = $1 AND id > $2 AND author_pkey <> $3 AND deleted_at IS NULL",
			array($pid, $last, $me));
		return array('lastRead' => $last, 'unread' => $unread);
	}

	private static function out($r, $users) {
		$deleted = $r['deleted_at'] !== null;
		return array(
			'id'          => (int)$r['id'],
			'rev'         => (int)$r['rev'],
			'clientMsgId' => $r['client_msg_id'],
			'author'      => MsStore::user($users, $r['author_pkey']),
			'text'        => $deleted ? '' : $r['body'],
			'refs'        => $deleted ? array() : self::refsOut($r['refs']),
			'createdAt'   => $r['created_at'],
			'deletedAt'   => $r['deleted_at'],
			'deletedBy'   => $deleted && $r['deleted_by'] !== null ? MsStore::user($users, $r['deleted_by']) : null,
		);
	}

	/** Stored refs as {type, id} (jsonb does not keep key order). */
	private static function refsOut($json) {
		$out = array();
		foreach ((array)json_decode($json, true) as $r) {
			if (isset($r['type'], $r['id'])) {
				$out[] = array('type' => $r['type'], 'id' => $r['id']);
			}
		}
		return $out;
	}

	/** Trimmed text, \r\n -> \n, no control characters but \n and \t; 1 to MAX_CHARS characters. */
	public static function cleanText($text) {
		if (!is_string($text) || preg_match('//u', $text) !== 1) {
			throw new MsHttpError(400, 'bad_request', 'text must be a UTF-8 string');
		}
		$text = str_replace(array("\r\n", "\r"), "\n", $text);
		$text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $text);
		$text = trim($text);
		if ($text === '') {
			throw new MsHttpError(400, 'bad_request', 'text is empty');
		}
		if (preg_match_all('/./us', $text) > self::MAX_CHARS) {
			throw new MsHttpError(400, 'too_long', 'A message can be at most ' . self::MAX_CHARS . ' characters',
				array('maxChars' => self::MAX_CHARS));
		}
		return $text;
	}

	/** Up to MAX_REFS {type, id} with type spot or micrograph; duplicates dropped. */
	public static function cleanRefs($refs) {
		if ($refs === null) {
			return array();
		}
		if (!is_array($refs) || count($refs) > self::MAX_REFS) {
			throw new MsHttpError(400, 'bad_request', 'refs must be a list of at most ' . self::MAX_REFS);
		}
		$out = array();
		$seen = array();
		foreach ($refs as $r) {
			$type = MsHttp::prop($r, 'type');
			$id = MsHttp::prop($r, 'id');
			if (!in_array($type, self::REF_TYPES, true) || !MsModel::isId($id)) {
				throw new MsHttpError(400, 'bad_request', 'each ref must be {type: spot|micrograph, id}');
			}
			$k = $type . ':' . $id;
			if (!isset($seen[$k])) {
				$seen[$k] = true;
				$out[] = array('type' => $type, 'id' => $id);
			}
		}
		return $out;
	}

	private static function notice($db, $pid, $rev) {
		MsLive::chat($db, $pid, $rev);
	}
}
