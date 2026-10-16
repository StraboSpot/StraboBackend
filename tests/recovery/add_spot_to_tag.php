<?php
/**
 * Recovery: add a Spot back to a project tag (geologic unit etc.)
 *
 * Restoring a Spot with replay_dataset_spots.php does not restore its tag
 * memberships, which live in the project's tag list. This adds the Spot id
 * to the tag's spots array and saves the project through insertProject, the
 * same path an app upload takes (search index follows).
 *
 * Built for the 2026-10-15 GSA field trip incident (unit "Codell" lost its
 * Spot "GSA FIELDTRIP Loc 1"), but generic.
 *
 * Usage:
 *   php add_spot_to_tag.php --userpkey=<pkey> --project=<id> --tag=<id> --spot=<id> [--apply]
 *
 * Dry run by default; pass --apply to write.
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

$opts = getopt('', ['userpkey:', 'project:', 'tag:', 'spot:', 'apply', 'help']);

if (isset($opts['help']) || !isset($opts['userpkey'], $opts['project'], $opts['tag'], $opts['spot'])) {
    line("Usage: php add_spot_to_tag.php --userpkey=<pkey> --project=<id> --tag=<id> --spot=<id> [--apply]");
    line("Dry run by default; pass --apply to write.");
    exit(isset($opts['help']) ? 0 : 1);
}

$userpkey  = (int)$opts['userpkey'];
$projectid = (int)$opts['project'];
$tagid     = (int)$opts['tag'];
$spotid    = (int)$opts['spot'];
$apply     = isset($opts['apply']);

$strabo = new StraboSpot($neodb, $userpkey, $db);

$spotname = $neodb->get_var("MATCH (s:Spot {id: $spotid}) WHERE s.userpkey = $userpkey RETURN s.name");
if ($spotname === null) fail("Spot $spotid not found for userpkey $userpkey.");

$project = $strabo->getProject($projectid);
if (!$project || empty($project->id)) fail("Project $projectid not found for userpkey $userpkey.");

$tag = null;
foreach (($project->tags ?? []) as $t) {
    if ((int)$t->id === $tagid) { $tag = $t; break; }
}
if ($tag === null) fail("Tag $tagid not found in project $projectid.");

$spots = isset($tag->spots) && is_array($tag->spots) ? $tag->spots : [];
line(($apply ? "APPLY" : "DRY RUN") . ": tag \"" . ($tag->name ?? '?') . "\" ($tagid) has " . count($spots) . " Spot(s)");

if (in_array($spotid, array_map('intval', $spots), true)) {
    line("Spot $spotid ($spotname) is already in the tag. Nothing to do.");
    exit(0);
}

line("[ADD] Spot $spotid ($spotname)");
if (!$apply) {
    line("DRY RUN: no changes made. Re-run with --apply to write.");
    exit(0);
}

// Save the WHOLE project as downloaded, with the one tag changed:
// insertProject replaces every property on the node (updateNode SET n =),
// so a partial payload would wipe description, preferences, etc.
$tag->spots = array_merge($spots, [$spotid]);
$strabo->insertProject(json_encode($project), $projectid);

$after = $strabo->getProject($projectid);
foreach (($after->tags ?? []) as $t) {
    if ((int)$t->id === $tagid) {
        line("Tag now has Spots: " . json_encode($t->spots ?? []));
    }
}
line("Tags in project: " . count($after->tags ?? []));
exit(0);
