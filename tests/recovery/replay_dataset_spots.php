<?php
/**
 * Recovery: replay dataset spots from a saved FeatureCollection
 *
 * Restores spots to a Field dataset from a FeatureCollection JSON file —
 * typically a POST /db/datasetspots/{id} body recovered from the `rawcache`
 * request log (rawcache retains one week of raw upload bodies).
 *
 * Built for the 2026-06-10 Feather River incident (spots LD16-LD22 deleted
 * from dataset 17780889844749 by stale-device uploads while the dataset
 * modified_timestamp gate was broken, 2025-12-06..2026-06-08), but generic:
 * any dataset/user/FeatureCollection works.
 *
 * By default only spots MISSING from the server are inserted — existing
 * server spots are never touched. Replay follows the same code path as
 * DatasetSpotsController::postAction (fixIncomingBasemaps -> insertSpot ->
 * addSpotToDataset -> setDatasetCenter), minus the destructive
 * delete-server-spots-not-in-payload step.
 *
 * After a successful apply the dataset's modified_timestamp is bumped to now
 * (unless --no-bump) so the restored state wins the upload gate against any
 * device still carrying a stale copy. IMPORTANT: after the replay each device
 * should UPLOAD its own new work first, THEN download the project (download is
 * a full local replace — downloading first destroys unsynced spots). A device
 * that locally edits its stale copy of THIS dataset before downloading can
 * still replace it wholesale, so download promptly after uploading.
 *
 * Usage:
 *   php replay_dataset_spots.php --file=<payload.json> --dataset=<id> --userpkey=<pkey> [options]
 *
 * Options:
 *   --only=<id1,id2,...>  Restrict to these spot ids (default: all in file)
 *   --apply               Actually write (default: DRY RUN, no changes)
 *   --no-bump             Do not bump the dataset's modified_timestamp
 *
 * Example (Feather River incident):
 *   docker exec strabo-php php /srv/app/www/tests/recovery/replay_dataset_spots.php \
 *     --file=/tmp/lucas23.json --dataset=17780889844749 --userpkey=8420 --apply
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');
include_once('includes/geophp/geoPHP.inc');
require_once('db/strabospotclass.php');

function line($s = '') { echo $s . "\n"; }
function fail($s) { line("ERROR: $s"); exit(1); }

// ---------------------------------------------------------------- arguments
$opts = getopt('', ['file:', 'dataset:', 'userpkey:', 'only::', 'apply', 'no-bump', 'help']);

if (isset($opts['help']) || !isset($opts['file'], $opts['dataset'], $opts['userpkey'])) {
    line("Usage: php replay_dataset_spots.php --file=<payload.json> --dataset=<id> --userpkey=<pkey>");
    line("       [--only=id1,id2,...] [--apply] [--no-bump]");
    line("Dry run by default; pass --apply to write.");
    exit(isset($opts['help']) ? 0 : 1);
}

$file      = $opts['file'];
$datasetid = (int)$opts['dataset'];
$userpkey  = (int)$opts['userpkey'];
$apply     = isset($opts['apply']);
$bump      = !isset($opts['no-bump']);
$only      = isset($opts['only']) && $opts['only'] !== false
    ? array_map('intval', explode(',', $opts['only']))
    : null;

if ($datasetid <= 0 || $userpkey <= 0) fail("--dataset and --userpkey must be positive integers.");
if (!is_readable($file)) fail("Cannot read file: $file");

$fc = json_decode(file_get_contents($file));
if (!$fc || ($fc->type ?? '') !== 'FeatureCollection' || empty($fc->features)) {
    fail("File is not a FeatureCollection with features: $file");
}

$strabo = new StraboSpot($neodb, $userpkey, $db);

line(($apply ? "APPLY" : "DRY RUN") . " — replay into dataset $datasetid (userpkey $userpkey)");
line("Payload: $file (" . count($fc->features) . " features)");
line();

// ------------------------------------------------------------- sanity checks
if (!$strabo->findDataset($datasetid)) {
    fail("Dataset $datasetid not found for userpkey $userpkey.");
}

$serverIds = $strabo->getDatasetSpotIds($datasetid);
$serverIds = is_array($serverIds) ? array_map('intval', $serverIds) : [];
line("Dataset currently has " . count($serverIds) . " spots on the server.");

// ------------------------------------------------------- classify payload
$toInsert = [];
foreach ($fc->features as $feature) {
    $spotid = (int)($feature->properties->id ?? 0);
    $name   = $feature->properties->name ?? '?';
    if ($spotid <= 0) { line("  [skip]    feature with no usable id (name: $name)"); continue; }
    if ($only !== null && !in_array($spotid, $only, true)) continue;

    if (in_array($spotid, $serverIds, true)) {
        line("  [present] $spotid  $name — already in dataset, untouched");
    }
    elseif ($strabo->findSpot($spotid)) {
        // Exists as this user's spot but not linked to this dataset; do not
        // re-home spots automatically — surface it for a human decision.
        line("  [exists]  $spotid  $name — spot exists OUTSIDE this dataset, skipped (resolve manually)");
    }
    elseif ($strabo->spotExistsInOtherDataset($spotid, $datasetid)) {
        line("  [exists]  $spotid  $name — found in another dataset, skipped (resolve manually)");
    }
    else {
        line("  [RESTORE] $spotid  $name");
        $toInsert[] = $feature;
    }
}

line();
if (empty($toInsert)) { line("Nothing to restore. Done."); exit(0); }
line(count($toInsert) . " spot(s) to restore.");

if (!$apply) {
    line("DRY RUN — no changes made. Re-run with --apply to write.");
    exit(0);
}

// ------------------------------------------------------------------- apply
// Same preprocessing as DatasetSpotsController::postAction: derives wkt
// (pixel->real-world for image-basemap spots, geometry passthrough otherwise).
$toInsert = $strabo->fixIncomingBasemaps($toInsert);

$restored = 0;
foreach ($toInsert as $feature) {
    $spotid = (int)$feature->properties->id;
    $strabo->insertSpot(json_encode($feature, JSON_PRETTY_PRINT), null, "", $userpkey);

    if (!$strabo->findSpot($spotid)) {
        fail("insertSpot did not create spot $spotid — aborting (already-restored spots remain).");
    }
    if (!$strabo->findSpotInDataset($datasetid, $spotid)) {
        $strabo->addSpotToDataset($datasetid, $spotid);
    }
    line("  restored $spotid (" . ($feature->properties->name ?? '?') . ")");
    $restored++;
}

$strabo->setDatasetCenter($datasetid, $userpkey);

if ($bump) {
    $now = (int)round(microtime(true) * 1000);
    $neodb->query("MATCH (d:Dataset {id: $datasetid}) WHERE d.userpkey = $userpkey
                   SET d.modified_timestamp = $now");
    line("Dataset modified_timestamp bumped to $now.");
}

// ------------------------------------------------------------------ verify
$finalIds = $strabo->getDatasetSpotIds($datasetid);
$finalIds = is_array($finalIds) ? $finalIds : [];
line();
line("Restored $restored spot(s). Dataset now has " . count($finalIds) . " spots on the server.");
line("Remind all devices: UPLOAD their own new work first, THEN download the project.");
exit(0);
