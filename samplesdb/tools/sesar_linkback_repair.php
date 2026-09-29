<?php
/**
 * File: samplesdb/tools/sesar_linkback_repair.php
 * Description: One-off repair (2026-09-29): link-back resources StraboSpot
 *              made at SESAR before this date carry uri_type "URL", which
 *              SESAR stores but its sample page never shows (it lists only
 *              "DOI", "LOCAL" and "regular URL" under Linked Resources). This
 *              sets uri_type to SesarClient::LINK_URI_TYPE on every link-back
 *              resource recorded in strabosamples.sesar_registrations for this
 *              server's SESAR environment. Safe to run twice.
 *
 *              Needs each owner's live SESAR connection (their tokens); owners
 *              without one are listed and skipped. Dry run unless --apply.
 *
 *                docker exec -u www-data <container> php /srv/app/www/samplesdb/tools/sesar_linkback_repair.php
 *                docker exec -u www-data <container> php /srv/app/www/samplesdb/tools/sesar_linkback_repair.php --apply
 *
 *              Exit 0 = done, 1 = some resources could not be fixed,
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
		fwrite(STDERR, "Unknown argument: $a\nUsage: php sesar_linkback_repair.php [--apply]\n");
		exit(2);
	}
}

chdir(__DIR__ . '/../..');
if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = getcwd();

include_once 'includes/config.inc.php';
include 'db.php';
require_once 'includes/sesar/SesarClient.php';
require_once 'includes/sesar/SesarConnection.php';

try {
	$env = SesarAccess::environment();
	echo "SESAR link-back repair (uri_type -> \"" . SesarClient::LINK_URI_TYPE . "\")\n";
	echo "  database:     " . $db->get_var("SELECT current_database()") . " on " . (isset($dbhost) ? $dbhost : '?') . "\n";
	echo "  server SESAR: $env (this server's \$sesar_env)\n";
	echo "  mode:         " . ($apply ? 'APPLY' : 'dry run (nothing changes; add --apply)') . "\n\n";

	$rows = $db->get_results_prepared(
		"SELECT r.sample_userpkey AS owner, u.email, r.related_resource_id AS rr, min(r.igsn) AS igsn, count(*) AS n
		   FROM strabosamples.sesar_registrations r
		   LEFT JOIN users u ON u.pkey = r.sample_userpkey
		  WHERE r.environment = $1 AND r.related_resource_id IS NOT NULL
		  GROUP BY r.sample_userpkey, u.email, r.related_resource_id
		  ORDER BY r.sample_userpkey, r.related_resource_id",
		array($env)
	);
	$byOwner = array();
	foreach ((is_array($rows) ? $rows : array()) as $r) $byOwner[(int)$r->owner][] = $r;
	echo count($rows ? $rows : array()) . " link-back resource(s), " . count($byOwner) . " owner(s)\n";

	$client = new SesarClient($env);
	$conn = new SesarConnection($db, $client);
	$fixed = 0;
	$failed = 0;
	foreach ($byOwner as $owner => $list) {
		echo "\n  " . ($list[0]->email !== null ? $list[0]->email : "user $owner") . " ($owner): " . count($list) . "\n";
		foreach ($list as $r) {
			$line = "    resource {$r->rr}  " . $r->igsn . ($r->n > 1 ? "  (+" . ($r->n - 1) . " more registration(s))" : '');
			if (!$apply) {
				echo "$line\n";
				continue;
			}
			try {
				$got = $conn->withAccess($owner, function ($access) use ($client, $r) {
					return $client->updateRelatedResource($access, (int)$r->rr, array('uri_type' => SesarClient::LINK_URI_TYPE));
				});
				$now = isset($got['uri_type']) ? $got['uri_type'] : '?';
				echo "$line  -> $now\n";
				$fixed++;
			} catch (SesarError $e) {
				echo "$line  FAILED: " . $e->getMessage() . "\n";
				$failed++;
				if (in_array($e->kind, array('auth', 'no_account'), true)) {
					echo "    (no usable SESAR connection for this owner; skipping the rest of theirs)\n";
					$failed += count($list) - 1;
					break;
				}
			}
		}
	}

	if ($apply) echo "\nFixed $fixed, failed $failed.\n";
	exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
	fwrite(STDERR, "Unexpected error: " . $e->getMessage() . "\n");
	exit(2);
}
