<?php
/**
 * File: load_fixture.php
 * Description: Helper for the StraboMicro2 client's workshop load test
 *              (tests/sync/load/run.mjs; dev only). Commands, each printing
 *              one JSON document:
 *                setup <n>      make load.owner + n load.NN accounts under
 *                               @test.strabospot.org, with 2-hour tokens
 *                join <pid>     the owner makes a join link, every account
 *                               asks with it, Approve All (timed)
 *                cleanup        delete those accounts and their projects
 *              Usage: docker exec strabo-php php /srv/app/www/tests/microsync/load_fixture.php setup 25
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (php_sapi_name() !== 'cli') {
	exit(1);
}

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microdb/lib/micro_join_web.php';
require_once '/srv/app/www/jwtmicrodb/strabomicroclass.php';

ini_set('display_errors', 'stderr');

$DOMAIN = '@test.strabospot.org';
$db->get_var('SELECT 1');
$cmd = isset($argv[1]) ? $argv[1] : '';

function load_pkeys($db, $DOMAIN) {
	$out = array();
	foreach ((array)$db->get_results_prepared("SELECT pkey, email FROM users WHERE email LIKE $1 ORDER BY email", array('load.%' . $DOMAIN)) as $r) {
		$out[$r->email] = (int)$r->pkey;
	}
	return $out;
}

function load_cleanup($db, $DOMAIN) {
	$pkeys = load_pkeys($db, $DOMAIN);
	if (count($pkeys) === 0) {
		return 0;
	}
	$list = json_encode(array_values($pkeys));
	$cond = "(SELECT jsonb_array_elements_text($1::jsonb)::int)";
	foreach ((array)$db->get_results_prepared("SELECT id, strabo_id, userpkey FROM strabomicro.micro_projectmetadata WHERE userpkey IN $cond", array($list)) as $r) {
		$sm = new StraboMicro(null, (int)$r->userpkey, $db);
		$sm->deleteProjectRows($r->strabo_id);
		$dir = '/srv/app/www/straboMicroFiles/' . (int)$r->id;
		if ((int)$r->id > 0 && is_dir($dir)) {
			exec('rm -rf ' . escapeshellarg($dir));
		}
	}
	$db->prepare_query("DELETE FROM strabomicro.micro_join_requests WHERE user_pkey IN $cond", array($list));
	$db->prepare_query("DELETE FROM strabomicro.micro_members WHERE user_pkey IN $cond", array($list));
	$db->prepare_query("DELETE FROM users WHERE pkey IN $cond", array($list));
	return count($pkeys);
}

if ($cmd === 'setup') {
	load_cleanup($db, $DOMAIN);
	$n = (int)$argv[2];
	$names = array('load.owner' . $DOMAIN);
	for ($i = 1; $i <= $n; $i++) {
		$names[] = sprintf('load.%02d', $i) . $DOMAIN;
	}
	foreach ($names as $email) {
		$db->prepare_query(
			"INSERT INTO users (email, password, firstname, lastname, hash, active, deleted)
			 VALUES ($1, crypt('loadtest', gen_salt('md5')), 'Load', $2, $3, true, false)",
			array($email, substr($email, 5, 5), bin2hex(random_bytes(8))));
	}
	$out = array();
	foreach (load_pkeys($db, $DOMAIN) as $email => $pkey) {
		$out[] = array('email' => $email, 'pkey' => $pkey, 'token' => token($pkey));
	}
	echo json_encode($out);
} elseif ($cmd === 'join') {
	$pid = (int)$argv[2];
	$ms = new MsDb($db);
	$pkeys = load_pkeys($db, $DOMAIN);
	$owner = $pkeys['load.owner' . $DOMAIN];
	$link = MsJoin::createLink($ms, $owner, $pid, 'contributor', 7);
	$t = microtime(true);
	foreach ($pkeys as $email => $pkey) {
		if ($pkey !== $owner) {
			MsJoin::ask($ms, $pkey, $link['code']);
		}
	}
	$askMs = (microtime(true) - $t) * 1000;
	$t = microtime(true);
	$all = MsJoin::approveAll($ms, $owner, $pid);
	echo json_encode(array('approved' => $all['approved'], 'refused' => $all['refused'], 'askMs' => round($askMs), 'approveAllMs' => round((microtime(true) - $t) * 1000)));
} elseif ($cmd === 'cleanup') {
	echo json_encode(array('removedAccounts' => load_cleanup($db, $DOMAIN)));
} else {
	fwrite(STDERR, "setup <n> | join <pid> | cleanup\n");
	exit(2);
}
