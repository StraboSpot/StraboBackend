<?php
/**
 * File: deleted.php
 * Description: Synced StraboMicro projects deleted by their owner (v3 §12b
 *              17ac, 17ad; microsync/lib/MsDelete.php). Lists them, restores
 *              one for its owner, or purges ahead of time. The worker's cron
 *              sweep purges projects 30 days after their delete on its own;
 *              the website (stage 6c) offers Restore to owners.
 *              Usage (as www-data inside strabo-php):
 *                php microsync/tools/deleted.php --list
 *                php microsync/tools/deleted.php --restore=123
 *                php microsync/tools/deleted.php --purge=123 --force
 *                php microsync/tools/deleted.php --purge-due
 *              --purge of a project still inside its 30 days needs --force
 *              (it cannot be restored afterwards).
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit();
}
set_time_limit(0);
chdir(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);

include_once "../../includes/config.inc.php";
include_once "../../db.php";
include_once "../../jwtmicrodb/strabomicroclass.php";
include_once "../lib/MsHttp.php";
include_once "../lib/MsDb.php";
include_once "../lib/MsModel.php";
include_once "../lib/MsStore.php";
include_once "../lib/MsDelete.php";

$opts = getopt('', array('list', 'restore:', 'purge:', 'purge-due', 'force'));
$db->get_var('SELECT 1');
$ms = new MsDb($db);

try {
	if (isset($opts['list'])) {
		foreach ($ms->rows("SELECT project_id FROM strabomicro.micro_deleted_projects ORDER BY deleted_at", array()) as $r) {
			$t = MsDelete::tombstone($ms, (int)$r['project_id']);
			printf("%d  %s  owner %d  deleted %s by %d  %s\n", $t['project_id'], $t['name'], $t['owner_pkey'],
				$t['deleted_at'], $t['deleted_by'],
				$t['purged_at'] !== null ? "purged {$t['purged_at']}" : ($t['restorable'] ? "restorable until {$t['restorable_until']}" : 'purge due'));
		}
	} elseif (isset($opts['restore'])) {
		$pid = (int)$opts['restore'];
		$t = MsDelete::tombstone($ms, $pid);
		if ($t === null) {
			fwrite(STDERR, "Project $pid is not deleted\n");
			exit(1);
		}
		MsDelete::restore($ms, $pid, (int)$t['owner_pkey']);
		echo "Restored $pid ({$t['name']}); the worker rebuilds its views on the next sweep\n";
	} elseif (isset($opts['purge'])) {
		$pid = (int)$opts['purge'];
		$t = MsDelete::tombstone($ms, $pid);
		if ($t === null || $t['purged_at'] !== null) {
			fwrite(STDERR, "Project $pid is not waiting for a purge\n");
			exit(1);
		}
		if ($t['restorable'] && !isset($opts['force'])) {
			fwrite(STDERR, "Project $pid can still be restored until {$t['restorable_until']}; add --force to purge it now\n");
			exit(1);
		}
		MsDelete::purge($db, $ms, $pid);
		echo "Purged $pid ({$t['name']})\n";
	} elseif (isset($opts['purge-due'])) {
		$done = MsDelete::purgeDue($db, $ms);
		echo 'Purged ' . count($done) . ($done ? ': ' . implode(', ', $done) : '') . "\n";
	} else {
		fwrite(STDERR, "usage: deleted.php --list | --restore=PID | --purge=PID [--force] | --purge-due\n");
		exit(2);
	}
} catch (MsHttpError $e) {
	fwrite(STDERR, $e->errorCode . ': ' . $e->getMessage() . "\n");
	exit(1);
}
