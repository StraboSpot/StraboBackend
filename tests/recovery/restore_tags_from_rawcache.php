<?php
/**
 * Recovery: put project tags (geologic units etc.) back from a logged upload
 *
 * Reads the tag list from an earlier POST /db/project body in the rawcache
 * request log and merges it into the project as it is now: tags missing from
 * the project are added, and tags that are present get any missing Spot ids
 * back. Nothing is removed. Spot ids that no longer exist for the user are
 * skipped. The whole project is saved through insertProject, the same path an
 * app upload takes (search index follows).
 *
 * Built for the 2026-10-15 GSA field trip incident (a second user's iPad
 * upload replaced the units the Pixel had uploaded), but generic.
 *
 * Usage:
 *   php restore_tags_from_rawcache.php --userpkey=<pkey> --project=<id> --rawcache=<pkey> [--apply]
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

$opts = getopt('', ['userpkey:', 'project:', 'rawcache:', 'apply', 'help']);

if (isset($opts['help']) || !isset($opts['userpkey'], $opts['project'], $opts['rawcache'])) {
    line("Usage: php restore_tags_from_rawcache.php --userpkey=<pkey> --project=<id> --rawcache=<pkey> [--apply]");
    line("Dry run by default; pass --apply to write.");
    exit(isset($opts['help']) ? 0 : 1);
}

$userpkey  = (int)$opts['userpkey'];
$projectid = (int)$opts['project'];
$rawpkey   = (int)$opts['rawcache'];
$apply     = isset($opts['apply']);

// The logged request must be this user's project upload.
$row = $db->get_row_prepared("select username, request_method, request_uri, rawdata, uploaddate from rawcache where pkey = $1", array($rawpkey));
if (!$row) fail("rawcache row $rawpkey not found (rows are kept about a week).");
$email = $db->get_var_prepared("select email from users where pkey = $1", array($userpkey));
if (strtolower((string)$row->username) !== strtolower((string)$email)) fail("rawcache row $rawpkey belongs to {$row->username}, not userpkey $userpkey.");
if ($row->request_method !== 'POST' || stripos($row->request_uri, '/project') === false) fail("rawcache row $rawpkey is {$row->request_method} {$row->request_uri}, not a project upload.");
$logged = json_decode($row->rawdata);
if (!$logged || (int)($logged->id ?? 0) !== $projectid) fail("rawcache row $rawpkey is not an upload of project $projectid.");

$strabo = new StraboSpot($neodb, $userpkey, $db);
$project = $strabo->getProject($projectid);
if (!$project || empty($project->id)) fail("Project $projectid not found for userpkey $userpkey.");
if (!isset($project->tags) || !is_array($project->tags)) $project->tags = [];

$spotExists = [];
$exists = function ($spotid) use (&$spotExists, $neodb, $userpkey) {
    $spotid = (int)$spotid;
    if (!isset($spotExists[$spotid])) {
        $spotExists[$spotid] = $neodb->get_var("MATCH (s:Spot {id: $spotid}) WHERE s.userpkey = $userpkey RETURN count(s)") > 0;
    }
    return $spotExists[$spotid];
};

$current = [];
foreach ($project->tags as $t) $current[(int)$t->id] = $t;

line(($apply ? "APPLY" : "DRY RUN") . ": project \"" . ($project->description->project_name ?? '?') . "\" ($projectid) has " . count($project->tags) . " tag(s)");
line("Source: rawcache $rawpkey, uploaded {$row->uploaddate}, " . count($logged->tags ?? []) . " tag(s)");
line();

$changes = 0;
foreach (($logged->tags ?? []) as $t) {
    $id = (int)$t->id;
    $spots = array_values(array_filter(array_map('intval', $t->spots ?? []), $exists));
    $skipped = count($t->spots ?? []) - count($spots);
    $note = $skipped ? " ($skipped Spot id(s) no longer exist, skipped)" : "";
    if (!isset($current[$id])) {
        $t->spots = $spots;
        $project->tags[] = $t;
        line("[ADD]   tag $id \"" . ($t->name ?? '?') . "\" with " . count($spots) . " Spot(s)$note");
        $changes++;
        continue;
    }
    $have = array_map('intval', $current[$id]->spots ?? []);
    $missing = array_values(array_diff($spots, $have));
    if ($missing) {
        $current[$id]->spots = array_merge($have, $missing);
        line("[SPOTS] tag $id \"" . ($t->name ?? '?') . "\" gets back " . json_encode($missing) . "$note");
        $changes++;
    } else {
        line("[ok]    tag $id \"" . ($t->name ?? '?') . "\" already complete");
    }
}

line();
if (!$changes) { line("Nothing to restore. Done."); exit(0); }
if (!$apply) { line("DRY RUN: no changes made. Re-run with --apply to write."); exit(0); }

// Save the WHOLE project: insertProject replaces every property on the node
// (updateNode SET n =), so a partial payload would wipe fields.
$strabo->insertProject(json_encode($project), $projectid);

$after = $strabo->getProject($projectid);
line("Tags in project now: " . count($after->tags ?? []));
foreach (($after->tags ?? []) as $t) line("  " . $t->id . " " . ($t->name ?? '?') . " " . json_encode($t->spots ?? []));
exit(0);
