<?php
/**
 * File: samplesdb/tools/sesar_deactivation_sweep.php
 * Description: Nightly check of pending SESAR deactivation requests (IGSN
 *              Phase 7, review Q1). For every registration in state
 *              'deactivation_requested' in the configured SESAR environment,
 *              an anonymous by-igsn lookup (8 in parallel); a 410 means a
 *              curator approved it, so the row becomes 'deactivated' and the
 *              IGSN is removed from the sample (SesarDeactivate::markGone).
 *              Denials are only visible to the owner's signed-in read, so
 *              they are picked up by "Check with SESAR now" on the sample,
 *              not here.
 *
 *              Prod host cron (as www-data, inside the app container):
 *                15 4 * * *  docker exec -u www-data <container> php /srv/app/www/samplesdb/tools/sesar_deactivation_sweep.php
 *
 *              Output: one summary line (checked / deactivated / lookup errors).
 *              Exit 0 = ran (lookup errors are retried the next night),
 *              2 = SESAR not configured or an unexpected error.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit("CLI only.\n");
}

chdir(__DIR__ . '/../..');
if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = getcwd();

include_once 'includes/config.inc.php';
include 'db.php';
include 'neodb.php';
require_once 'includes/sesar/SesarDeactivate.php';

if (!SesarAccess::isConfigured()) {
	fwrite(STDERR, "SESAR is not configured on this server.\n");
	exit(2);
}

try {
	$client = new SesarClient();
	$deact = new SesarDeactivate($db, $client, new SesarConnection($db, $client), SesarDeactivate::serviceIgsnClearer($db, $neodb));
	$r = $deact->sweep();
	echo gmdate('Y-m-d\TH:i:s\Z') . ' sesar deactivation sweep (' . $client->environment() . '): checked ' . $r['checked']
		. ', deactivated ' . $r['deactivated'] . ', lookup errors ' . $r['errors'] . "\n";
	exit(0);
} catch (Throwable $e) {
	fwrite(STDERR, 'sesar deactivation sweep failed: ' . $e->getMessage() . "\n");
	exit(2);
}
