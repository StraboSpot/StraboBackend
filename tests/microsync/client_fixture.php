<?php
/**
 * File: client_fixture.php
 * Description: Helper for the StraboMicro2 client's sync tests (dev only).
 *              Commands, each printing one JSON document:
 *                token                fixture owner's pkey and a 2-hour JWT
 *                build <pid>          run the worker for a project now
 *                assembled <pid>      the project as the store assembles it
 *                                     (project.json, point counts, refs)
 *                cleanup              delete every project whose straboId
 *                                     starts with mscli- (rows, files, staging)
 *              Usage: docker exec strabo-php php /srv/app/www/tests/microsync/client_fixture.php token
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
require_once '/srv/app/www/includes/jwt/quick-jwt.php';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microsync/lib/MsConvert.php';
require_once '/srv/app/www/jwtmicrodb/strabomicroclass.php';

$PREFIX = 'mscli-';
$FILES = '/srv/app/www/straboMicroFiles';
$EMAIL = 'owner@test.strabospot.org';

function out($v) {
	echo json_encode($v, MsHttp::JSON_OUT | JSON_PRETTY_PRINT) . "\n";
}

$db->get_var('SELECT 1');
$cmd = isset($argv[1]) ? $argv[1] : '';
$pid = isset($argv[2]) ? (int)$argv[2] : 0;

if ($cmd === 'token') {
	$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND deleted = false", array($EMAIL));
	if ($pkey <= 0) {
		fwrite(STDERR, "Missing fixture user $EMAIL (run tests/collaboration/setup_test_data.php)\n");
		exit(2);
	}
	out(array('pkey' => $pkey, 'email' => $EMAIL, 'token' => token($pkey)));
} elseif ($cmd === 'build' && $pid > 0) {
	out(array('result' => build_now($pid)));
} elseif ($cmd === 'assembled' && $pid > 0) {
	$sid = $db->get_var_prepared("SELECT strabo_id FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
	if (!is_string($sid) || strpos($sid, $PREFIX) !== 0) {
		fwrite(STDERR, "Not a client test project: $pid\n");
		exit(2);
	}
	$a = MsWorker::assemble(new MsDb($db), $pid, $sid);
	out($a === null ? null : array('project' => json_decode($a['json']), 'pointCounts' => array_values($a['pointCounts']), 'refs' => $a['refs']));
} elseif ($cmd === 'cleanup') {
	$rows = $db->get_results_prepared("SELECT id, strabo_id, userpkey FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1",
		array($PREFIX . '%'));
	$n = 0;
	foreach ((array)$rows as $r) {
		$p = (int)$r->id;
		foreach ((array)$db->get_results_prepared("SELECT upload_id FROM strabomicro.micro_uploads WHERE project_id = $1", array($p)) as $u) {
			@unlink("$FILES/_staging/" . $u->upload_id);
		}
		// The worker built legacy rows (relational tables, search slice, samples
		// spine) for a ready project: remove them the way the website does
		$sm = new StraboMicro(null, (int)$r->userpkey, $db);
		$sm->deleteProjectRows($r->strabo_id);
		if ($p > 0 && is_dir("$FILES/$p")) {
			exec('rm -rf ' . escapeshellarg("$FILES/$p"));
		}
		$n++;
	}
	$left = (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	out(array('removed' => $n, 'left' => $left));
} else {
	fwrite(STDERR, "usage: client_fixture.php token | build <pid> | assembled <pid> | cleanup\n");
	exit(1);
}
