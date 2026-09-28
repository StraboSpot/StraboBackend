<?php
/**
 * File: samplesdb/tools/sesar_sandbox_cleanup.php
 * Description: IGSN launch cleanup (Phase 9, rollout step 2): removes SESAR
 *              SANDBOX IGSNs from sample IGSN fields before prod switches to
 *              production SESAR. Rules in includes/sesar/SesarSandboxCleanup.php.
 *
 *              Dry run unless --apply. Order on prod (runbook): dry run, fix
 *              the report-only lists by hand, --apply, switch $sesar_env to
 *              'production', dry run again (expect nothing).
 *
 *                docker exec -u www-data <container> php /srv/app/www/samplesdb/tools/sesar_sandbox_cleanup.php
 *                docker exec -u www-data <container> php /srv/app/www/samplesdb/tools/sesar_sandbox_cleanup.php --apply
 *
 *              Exit 0 = done, 1 = some samples could not be cleared,
 *              2 = unexpected error or bad arguments.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit("CLI only.\n");
}

$apply = false;
foreach (array_slice($argv, 1) as $a) {
	if ($a === '--apply') {
		$apply = true;
	} else {
		fwrite(STDERR, "Unknown argument: $a\nUsage: php sesar_sandbox_cleanup.php [--apply]\n");
		exit(2);
	}
}

chdir(__DIR__ . '/../..');
if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = getcwd();

include_once 'includes/config.inc.php';
include 'db.php';
include 'neodb.php';
require_once 'includes/sesar/SesarSandboxCleanup.php';
require_once 'includes/sesar/SesarDeactivate.php';

try {
	$dbName = $db->get_var("SELECT current_database()");
	echo "SESAR sandbox cleanup\n";
	echo "  database:     $dbName on " . (isset($dbhost) ? $dbhost : '?') . "\n";
	echo "  server SESAR: " . SesarAccess::environment() . " (this server's \$sesar_env)\n";
	echo "  mode:         " . ($apply ? 'APPLY (sample IGSN fields will be cleared)' : 'dry run (nothing changes; add --apply to clear)') . "\n\n";

	$cleanup = new SesarSandboxCleanup($db, new SesarClient('production'), SesarDeactivate::serviceIgsnClearer($db, $neodb));
	$plan = $cleanup->plan();

	$emails = array();
	$email = function ($pkey) use ($db, &$emails) {
		if (!isset($emails[$pkey])) $emails[$pkey] = (string)$db->get_var_prepared("SELECT email FROM users WHERE pkey = $1", array((int)$pkey));
		return $emails[$pkey];
	};
	$line = function ($i) use ($email) {
		return '  ' . $email($i['owner']) . ' | ' . $i['sample_id'] . ' | ' . ($i['name'] !== '' ? $i['name'] : '(no name)') . ' | ' . $i['field'];
	};

	echo "1. Sandbox IGSNs to clear (" . count($plan['clear']) . ")  [owner | sample id | name | IGSN field]\n";
	foreach ($plan['clear'] as $i) echo $line($i) . "\n";
	echo "   Also: " . $plan['already_clear'] . " tracked sample(s) already empty, " . $plan['sample_gone'] . " row(s) whose sample no longer exists.\n";
	if ($plan['changed']) {
		echo "   Left alone, the field now holds something else (" . count($plan['changed']) . "):\n";
		foreach ($plan['changed'] as $i) echo '  ' . $line($i) . '   (tracked: ' . $i['igsn'] . ")\n";
	}

	echo "\n2. REPORT ONLY: IGSNs typed in by hand (no tracking row) that real SESAR does not know (" . count($plan['untracked']) . ")\n";
	echo "   Likely sandbox test values. Fix by hand in the sample's Edit Metadata.\n";
	foreach ($plan['untracked'] as $i) echo $line($i) . "\n";
	if ($plan['unchecked']) {
		echo "   Could not check at real SESAR, run again later (" . count($plan['unchecked']) . "):\n";
		foreach ($plan['unchecked'] as $i) echo '  ' . $line($i) . '   (' . $i['message'] . ")\n";
	}

	echo "\n3. REPORT ONLY: samples created from sandbox SESAR records (" . count($plan['created']) . ")\n";
	echo "   No Field/Micro/Exp links and no children. Delete by hand if they were only for testing (curl asks for the password):\n";
	foreach ($plan['created'] as $i) {
		echo '  ' . $email($i['owner']) . ' | ' . $i['sample_id'] . ' | ' . $i['name'] . ' | created ' . substr($i['created_at'], 0, 19) . ' | ' . $i['igsn'] . "\n";
		echo '     curl -u ' . $email($i['owner']) . ' -X DELETE https://strabospot.org/samplesdb/sample/' . rawurlencode($i['sample_id']) . "\n";
	}

	if (!$apply) {
		echo "\nDry run: nothing changed.\n";
		exit(0);
	}
	$r = $cleanup->apply($plan);
	echo "\nApplied: cleared " . $r['cleared'] . ', skipped ' . $r['skipped'] . ' (changed since the plan), failed ' . count($r['failed']) . "\n";
	foreach ($r['failed'] as $f) echo '  FAILED ' . $email($f['owner']) . ' | ' . $f['sample_id'] . ' | ' . $f['error'] . "\n";
	exit($r['failed'] ? 1 : 0);
} catch (Throwable $e) {
	fwrite(STDERR, 'sesar sandbox cleanup failed: ' . $e->getMessage() . "\n");
	exit(2);
}
