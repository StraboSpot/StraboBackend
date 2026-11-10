<?php
/**
 * File: includes/fieldcamp/build_tiles.php
 * Description: Field Camp Wizard: build a camp's offline map tiles. Command
 *              line only; FieldCampTiles::startBuild() starts it in the
 *              background (php build_tiles.php <camp pkey>). Progress and the
 *              result go into the camp's build_status / build_message columns,
 *              which the Offline maps step shows.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (php_sapi_name() !== 'cli') { http_response_code(404); exit("Not found."); }

$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['DOCUMENT_ROOT'] = $root;
set_time_limit(0);

require_once 'includes/config.inc.php';
require_once 'db.php';
require_once __DIR__ . '/FieldCamp.php';
require_once __DIR__ . '/FieldCampPackage.php';
require_once __DIR__ . '/FieldCampTiles.php';

$camp = FieldCamp::getCamp($db, (int)($argv[1] ?? 0));
if (!$camp) { fwrite(STDERR, "No such camp.\n"); exit(1); }

try {
	$layers = FieldCampTiles::layers(FieldCampPackage::customMapsForCamp($db, $camp));
	$result = FieldCampTiles::build($db, $camp, $layers);
} catch (\Throwable $e) {
	error_log("build_tiles camp " . (int)$camp->pkey . ": " . $e->getMessage());
	$db->prepare_query("update fieldcamp set build_status = 'failed', build_message = $2, build_finished = now() where pkey = $1",
		array((int)$camp->pkey, "The build stopped with an error. Please start it again."));
	exit(1);
}
echo ($result['error'] !== '' ? "FAILED: " . $result['error'] : "OK: " . $result['tiles'] . " tiles in " . $result['maps'] . " maps") . "\n";
exit($result['error'] !== '' ? 1 : 0);
