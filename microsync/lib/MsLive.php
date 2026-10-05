<?php
/**
 * File: MsLive.php
 * Description: Notices for the real-time channel (collaboration spec v3,
 *              17ah-17ak, 17as). PHP never talks to the live service
 *              directly: it sends a Postgres NOTIFY on the channel
 *              'microsync_live', and the strabo-live container (livesvc/)
 *              LISTENs on the same database and passes the notice on to the
 *              apps that follow the project.
 *
 *              NOTIFY inside a transaction is delivered only when it commits
 *              (and never after a rollback), so callers send it before their
 *              commit, next to the write it announces. Notices carry ids
 *              only, never project content:
 *                {"t":"changed","pid":N,"seq":S,"by":"<clientId>"|null}
 *                    the project's head moved to S (a push or a file ref)
 *                {"t":"members","pid":N}
 *                    membership changed (role, removal, leave, transfer,
 *                    accepted invitation, delete or restore): the service
 *                    checks its followers of N again
 *                {"t":"parked","pid":N}
 *                    a push was parked for the owner's review, or the
 *                    owner reviewed one: the owner's copies count again
 *              If strabo-live is down nothing changes here: the NOTIFY has
 *              no listener and the apps poll (17ak, 17ao).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsLive {

	const CHANNEL = 'microsync_live';

	/** The project's head moved to $seq; $clientId is the pushing copy's id, if known. */
	public static function changed($db, $pid, $seq, $clientId = null) {
		self::send($db, array('t' => 'changed', 'pid' => (int)$pid, 'seq' => (int)$seq,
			'by' => is_string($clientId) ? $clientId : null));
	}

	/** Membership of the project changed. */
	public static function members($db, $pid) {
		self::send($db, array('t' => 'members', 'pid' => (int)$pid));
	}

	/** Parked pushes of the project changed (a new or longer parked push, or a review). */
	public static function parked($db, $pid) {
		self::send($db, array('t' => 'parked', 'pid' => (int)$pid));
	}

	private static function send($db, $notice) {
		$db->q('SELECT pg_notify($1, $2)', array(self::CHANNEL, json_encode($notice)));
	}
}
