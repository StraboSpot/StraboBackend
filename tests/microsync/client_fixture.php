<?php
/**
 * File: client_fixture.php
 * Description: Helper for the StraboMicro2 client's sync tests (dev only).
 *              Commands, each printing one JSON document:
 *                token [email]        pkey and a 2-hour JWT of the fixture owner,
 *                                     or of another @test.strabospot.org user
 *                build <pid>          run the worker for a project now
 *                assembled <pid>      the project as the store assembles it
 *                                     (project.json, point counts, refs);
 *                                     mscli- projects and e2e.* owners only
 *                cleanup              delete every project whose straboId
 *                                     starts with mscli- (rows, files, staging,
 *                                     deleted-project tombstones)
 *                wipe-e2e             the same for every project owned by an
 *                                     e2e.*@test.strabospot.org account (the
 *                                     app's end-to-end tests, tests/e2e), plus
 *                                     their memberships elsewhere
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

/** Owners whose projects the app's end-to-end tests may wipe */
$E2E_EMAILS = 'e2e.%@test.strabospot.org';

/**
 * Remove a project completely: rows (with the worker-built legacy rows,
 * the way the website does), files, staging uploads.
 */
function remove_project($db, $FILES, $r) {
	$p = (int)$r->id;
	foreach ((array)$db->get_results_prepared("SELECT upload_id FROM strabomicro.micro_uploads WHERE project_id = $1", array($p)) as $u) {
		@unlink("$FILES/_staging/" . $u->upload_id);
	}
	$sm = new StraboMicro(null, (int)$r->userpkey, $db);
	$sm->deleteProjectRows($r->strabo_id);
	foreach (array("$FILES/$p", "$FILES/_deleted/$p") as $dir) {
		if ($p > 0 && is_dir($dir)) {
			exec('rm -rf ' . escapeshellarg($dir));
		}
	}
}

function out($v) {
	echo json_encode($v, MsHttp::JSON_OUT | JSON_PRETTY_PRINT) . "\n";
}

$db->get_var('SELECT 1');
$cmd = isset($argv[1]) ? $argv[1] : '';
$pid = isset($argv[2]) ? (int)$argv[2] : 0;

if ($cmd === 'token') {
	$email = isset($argv[2]) ? (string)$argv[2] : $EMAIL;
	if (substr($email, -strlen('@test.strabospot.org')) !== '@test.strabospot.org') {
		fwrite(STDERR, "Only @test.strabospot.org fixture users\n");
		exit(2);
	}
	$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND deleted = false", array($email));
	if ($pkey <= 0) {
		fwrite(STDERR, "Missing fixture user $email (run tests/collaboration/setup_test_data.php)\n");
		exit(2);
	}
	out(array('pkey' => $pkey, 'email' => $email, 'token' => token($pkey)));
} elseif ($cmd === 'build' && $pid > 0) {
	out(array('result' => build_now($pid)));
} elseif ($cmd === 'assembled' && $pid > 0) {
	$sid = $db->get_var_prepared("SELECT strabo_id FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
	$e2eOwned = (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_projectmetadata p JOIN users u ON u.pkey = p.userpkey
		WHERE p.id = $1 AND u.email LIKE $2", array($pid, $E2E_EMAILS)) > 0;
	if (!is_string($sid) || (strpos($sid, $PREFIX) !== 0 && !$e2eOwned)) {
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
		remove_project($db, $FILES, $r);
		$n++;
	}
	// Projects the app deleted from StraboSpot (stage 6): their tombstones
	$db->prepare_query("DELETE FROM strabomicro.micro_deleted_projects WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	$left = (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	out(array('removed' => $n, 'left' => $left));
} elseif ($cmd === 'wipe-e2e') {
	$owners = "SELECT pkey FROM users WHERE email LIKE $1";
	$rows = $db->get_results_prepared("SELECT id, strabo_id, userpkey FROM strabomicro.micro_projectmetadata WHERE userpkey IN ($owners)",
		array($E2E_EMAILS));
	$n = 0;
	foreach ((array)$rows as $r) {
		remove_project($db, $FILES, $r);
		$n++;
	}
	// Their deleted projects' tombstones, and memberships in other projects
	$db->prepare_query("DELETE FROM strabomicro.micro_deleted_projects WHERE owner_pkey IN ($owners)", array($E2E_EMAILS));
	$db->prepare_query("DELETE FROM strabomicro.micro_members WHERE user_pkey IN ($owners)", array($E2E_EMAILS));
	$left = (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_projectmetadata WHERE userpkey IN ($owners)", array($E2E_EMAILS));
	out(array('removed' => $n, 'left' => $left));
} else {
	fwrite(STDERR, "usage: client_fixture.php token | build <pid> | assembled <pid> | cleanup | wipe-e2e\n");
	exit(1);
}
