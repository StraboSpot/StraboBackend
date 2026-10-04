<?php
/**
 * File: MsHistory.php
 * Description: A synced StraboMicro project's history, read from the change
 *              log (strabomicro.micro_changes, kept forever): the list the
 *              app's Activity panel and the website history page show
 *              (v3 §12b 17v, 17ae), and the project as it was at any change
 *              ("download as of a date", 17ae).
 *
 *              Every write to a synced project's entities and file refs is
 *              logged with the item's full state after it (parent, body,
 *              child order, refs), so the project as of change S is, for
 *              each item, the state after its last change at or before S,
 *              leaving out items whose last change was a delete. Blobs are
 *              never removed before the purge, so the files of any past
 *              state are still there. History starts when sync was turned on.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/MsHttp.php';
require_once __DIR__ . '/MsDb.php';
require_once __DIR__ . '/MsModel.php';
require_once __DIR__ . '/MsStore.php';

class MsHistory {

	/** Changed paths that are bookkeeping, not someone's edit (hidden in the list). */
	const NOISE_PATHS = array('childOrder', 'modifiedTimestamp', 'refs.tiles', 'refs.tiles_affine', 'refs.thumbnail');
	/** Also bookkeeping on these types: the app stamps date when it saves. */
	const NOISE_DATE_TYPES = array('project', 'dataset');
	/** Longest value text in a row's field details. */
	const VALUE_CHARS = 300;

	// ------------------------------------------------------------------
	// Range and points in time
	// ------------------------------------------------------------------

	/**
	 * First and last change of a project: firstSeq, firstAt, lastSeq,
	 * lastAt (ISO 8601 UTC), or null when nothing was logged.
	 */
	public static function range($db, $pid) {
		$r = $db->row(
			"SELECT min(seq) AS first_seq, max(seq) AS last_seq,
			        " . MsDb::iso('min(at)') . " AS first_at, " . MsDb::iso('max(at)') . " AS last_at
			   FROM strabomicro.micro_changes WHERE project_id = $1",
			array($pid));
		if ($r === null || $r['first_seq'] === null) {
			return null;
		}
		return array('firstSeq' => (int)$r['first_seq'], 'firstAt' => $r['first_at'],
			'lastSeq' => (int)$r['last_seq'], 'lastAt' => $r['last_at']);
	}

	/**
	 * The last change at or before a moment (unix seconds; the whole second
	 * counts, so "as of 15:40:12" includes a change at 15:40:12.6); 0 when it
	 * is before the history.
	 */
	public static function seqAt($db, $pid, $ts) {
		return (int)$db->val(
			"SELECT COALESCE(max(seq), 0) FROM strabomicro.micro_changes
			  WHERE project_id = $1 AND at < to_timestamp($2::double precision + 1)",
			array($pid, $ts));
	}

	/**
	 * When the project reached its state as of $seq: the time of its last
	 * change at or before it (unix seconds, whole), or null before the history.
	 */
	public static function timeOf($db, $pid, $seq) {
		$v = $db->val(
			"SELECT floor(extract(epoch FROM at)) FROM strabomicro.micro_changes
			  WHERE project_id = $1 AND seq <= $2 ORDER BY seq DESC LIMIT 1",
			array($pid, $seq));
		return $v === null ? null : (int)$v;
	}

	/**
	 * The change to rebuild for "as of": $seq (the project right after that
	 * change; "before change S" is S - 1) or else $ts (unix seconds; the last
	 * change at or before it). Errors: 400 when neither is given or the seq
	 * is past the history, 409 before_history (with historyStart) when the
	 * moment is before sync was turned on.
	 */
	public static function resolveAsOf($db, $pid, $seq, $ts) {
		$range = self::range($db, $pid);
		if ($seq === null && $ts === null) {
			throw new MsHttpError(400, 'bad_request', 'Give seq or at');
		}
		if ($seq === null) {
			$seq = self::seqAt($db, $pid, $ts);
		} elseif ($range !== null && $seq > $range['lastSeq']) {
			throw new MsHttpError(400, 'bad_request', 'seq is past the last change');
		}
		if ($range === null || $seq < $range['firstSeq']) {
			throw new MsHttpError(409, 'before_history', 'The history of this project starts when sync was turned on',
				array('historyStart' => $range === null ? null : $range['firstAt']));
		}
		return $seq;
	}

	// ------------------------------------------------------------------
	// The project as of a change
	// ------------------------------------------------------------------

	/**
	 * Entity rows and ref rows as of change $seq, in the shapes
	 * MsWorker::assemble reads from micro_entities and micro_blob_refs (JSON
	 * text taken straight from the log, so bodies keep their key order).
	 * Ordered like micro_entities by created_at: the time of an item's first
	 * change is its row's created_at (both now() of the creating push).
	 */
	public static function rowsAt($db, $pid, $seq) {
		$found = $db->rows(
			"SELECT entity_type, entity_id, after->>'parentType' AS parent_type, after->>'parentId' AS parent_id,
			        (after->'body')::text AS body, (after->'childOrder')::text AS child_order,
			        (after->'refs')::text AS refs
			   FROM (SELECT DISTINCT ON (entity_type, entity_id) entity_type, entity_id, op, after,
			                min(at) OVER (PARTITION BY entity_type, entity_id) AS first_at
			           FROM strabomicro.micro_changes
			          WHERE project_id = $1 AND seq <= $2
			          ORDER BY entity_type, entity_id, seq DESC) last
			  WHERE op <> 'delete' AND after IS NOT NULL
			  ORDER BY first_at, entity_id",
			array($pid, $seq));
		$rows = array();
		$refRows = array();
		foreach ($found as $r) {
			$refs = $r['refs'] === null ? null : json_decode($r['refs'], true);
			if (is_array($refs)) {
				foreach ($refs as $role => $sha) {
					if (is_string($sha)) {
						$refRows[] = array('entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'],
							'role' => (string)$role, 'sha256' => $sha);
					}
				}
			}
			unset($r['refs']);
			$rows[] = $r;
		}
		return array($rows, $refRows);
	}

	/**
	 * Name of a copy as of a moment: "<name> (as of Aug 12, 2026 3:40 PM)",
	 * in the viewer's time zone (IANA name) when it is valid, else UTC.
	 */
	public static function asOfName($name, $ts, $tz) {
		$zone = self::zone($tz);
		$when = self::localTime($ts, $zone)->format('M j, Y g:i A') . ($zone === null ? ' UTC' : '');
		$name = trim((string)$name);
		return ($name === '' ? 'Untitled Project' : $name) . " (as of $when)";
	}

	/** The same moment for a file name: 2026-08-12_1540 in that zone (else UTC). */
	public static function asOfStamp($ts, $tz) {
		return self::localTime($ts, self::zone($tz))->format('Y-m-d_Hi');
	}

	/** A valid IANA zone, else null. */
	private static function zone($tz) {
		return is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true) ? new DateTimeZone($tz) : null;
	}

	private static function localTime($ts, $zone) {
		$d = new DateTime('@' . (int)$ts);
		$d->setTimezone($zone === null ? new DateTimeZone('UTC') : $zone);
		return $d;
	}

	// ------------------------------------------------------------------
	// The list (Activity panel, website history page)
	// ------------------------------------------------------------------

	/**
	 * One page of the history, newest first, without bookkeeping updates
	 * (child order, timestamps, derived files). Options:
	 *   before   seq: rows older than it (0 = from the newest)
	 *   limit    rows per page
	 *   me       the viewer (pkey), for here
	 *   clientId the viewer's computer, for here (my own change from it)
	 *   user     pkey: only that person's changes, incl. their changes the
	 *            owner accepted (on_behalf_of); 0 = everyone
	 *   until    unix seconds: only changes at or before it, the whole
	 *            second included (go to date)
	 *   detail   true: each update carries fields (path, before, after as
	 *            short text; file refs as file: true)
	 * Returns more (older rows exist) and changes.
	 */
	public static function page($db, $pid, $opts) {
		$limit = isset($opts['limit']) ? (int)$opts['limit'] : 200;
		$detail = !empty($opts['detail']);
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
			        (c.user_pkey = $6 AND $7 <> '' AND (c.push_id IS NULL OR COALESCE(ps.client_id, '') = $7)) AS here,
			        CASE WHEN $10::boolean AND c.op = 'update' THEN c.before::text END AS before,
			        CASE WHEN $10::boolean AND c.op = 'update' THEN c.after::text END AS after
			   FROM strabomicro.micro_changes c
			   LEFT JOIN strabomicro.micro_pushes ps ON ps.push_id = c.push_id
			  WHERE c.project_id = $1 AND ($2::bigint = 0 OR c.seq < $2::bigint)
			    AND NOT (c.op = 'update' AND c.changed_paths IS NOT NULL AND c.changed_paths <@
			             (CASE WHEN c.entity_type IN ('project', 'dataset') THEN $3::text[] ELSE $4::text[] END))
			    AND ($8::int = 0 OR c.user_pkey = $8::int OR c.on_behalf_of = $8::int)
			    AND ($9::double precision IS NULL OR c.at < to_timestamp($9::double precision + 1))
			  ORDER BY c.seq DESC LIMIT $5",
			array($pid, isset($opts['before']) ? (int)$opts['before'] : 0, $noiseDated, $noise, $limit + 1,
			      isset($opts['me']) ? (int)$opts['me'] : 0, isset($opts['clientId']) ? (string)$opts['clientId'] : '',
			      isset($opts['user']) ? (int)$opts['user'] : 0,
			      isset($opts['until']) && $opts['until'] !== null ? (float)$opts['until'] : null,
			      $detail ? 't' : 'f'));
		$more = count($rows) > $limit;
		if ($more) {
			array_pop($rows);
		}
		$users = MsStore::users($db, array_merge(array_column($rows, 'user_pkey'), array_column($rows, 'on_behalf_of')));
		$out = array();
		foreach ($rows as $r) {
			$paths = $r['changed_paths'] === null ? null : json_decode($r['changed_paths'], true);
			if (is_array($paths)) {
				$paths = array_values(array_diff($paths, self::hiddenPaths($r['entity_type'])));
			}
			$entry = array(
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
			if ($detail) {
				$entry['fields'] = $r['op'] === 'update' && is_array($paths)
					? self::fieldChanges($paths, json_decode((string)$r['before']), json_decode((string)$r['after']))
					: array();
			}
			$out[] = $entry;
		}
		return array('more' => $more, 'changes' => $out);
	}

	/** Paths the list leaves out for an entity type. */
	public static function hiddenPaths($type) {
		return in_array($type, self::NOISE_DATE_TYPES, true)
			? array_merge(self::NOISE_PATHS, array('date')) : self::NOISE_PATHS;
	}

	/**
	 * What an update changed, per path: {path, before, after} with the
	 * values as short text (null = absent), {path, file: true} for a file
	 * ref, and parentId as the old and new parent ids.
	 */
	public static function fieldChanges($paths, $before, $after) {
		$out = array();
		foreach ($paths as $path) {
			if (strpos($path, 'refs.') === 0) {
				$out[] = array('path' => $path, 'file' => true);
			} elseif ($path === 'parentId') {
				$out[] = array('path' => $path, 'before' => MsHttp::prop($before, 'parentId'), 'after' => MsHttp::prop($after, 'parentId'));
			} else {
				$out[] = array('path' => $path,
					'before' => self::shortText(self::valueAt(MsHttp::prop($before, 'body'), $path)),
					'after'  => self::shortText(self::valueAt(MsHttp::prop($after, 'body'), $path)));
			}
		}
		return $out;
	}

	/** The value at a dotted path in a body (MsModel::applyFields paths); null when absent. */
	public static function valueAt($body, $path) {
		$node = $body;
		foreach (explode('.', $path) as $p) {
			if (!is_object($node) || !property_exists($node, $p)) {
				return null;
			}
			$node = $node->$p;
		}
		return $node;
	}

	/** A value as text of at most VALUE_CHARS characters ("..." when cut); null stays null. */
	public static function shortText($v) {
		if ($v === null) {
			return null;
		}
		if (is_bool($v)) {
			$s = $v ? 'true' : 'false';
		} elseif (is_string($v)) {
			$s = $v;
		} else {
			$s = json_encode($v, MsHttp::JSON_OUT);
		}
		if (preg_match('/^.{' . self::VALUE_CHARS . '}(?=.)/su', $s, $m)) {
			return $m[0] . '...';
		}
		return $s;
	}
}
