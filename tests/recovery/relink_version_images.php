<?php
/**
 * Recovery: reattach image files to restored Spots from version snapshots
 *
 * Companion to replay_dataset_spots.php. Replaying a Spot from a rawcache
 * upload body recreates its (:Image) nodes from properties.images, but the
 * nodes have no filename: the file link lived only on the deleted node.
 * Every version snapshot copies the project's image files to
 * versions/images/<image id> (createVersion), so a deleted Spot's photos can
 * usually be found there. For each image of the given Spots whose node has
 * no filename, this copies versions/images/<id> into dbimages/ under a new
 * image_seq filename and sets filename, origfilename and imagesha1 on the
 * node, the same way switchVersion restores images.
 *
 * Built for the 2026-10-15 GSA field trip incident (project 17920738457333,
 * iPad Spots lost to version restores), but generic.
 *
 * Usage:
 *   php relink_version_images.php --userpkey=<pkey> --spots=<id1,id2,...> [--apply]
 *
 * Dry run by default; pass --apply to write.
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

function line($s = '') { echo $s . "\n"; }
function fail($s) { line("ERROR: $s"); exit(1); }

$opts = getopt('', ['userpkey:', 'spots:', 'apply', 'help']);

if (isset($opts['help']) || !isset($opts['userpkey'], $opts['spots'])) {
    line("Usage: php relink_version_images.php --userpkey=<pkey> --spots=<id1,id2,...> [--apply]");
    line("Dry run by default; pass --apply to write.");
    exit(isset($opts['help']) ? 0 : 1);
}

$userpkey = (int)$opts['userpkey'];
$spotids  = array_filter(array_map('intval', explode(',', $opts['spots'])));
$apply    = isset($opts['apply']);

if ($userpkey <= 0 || empty($spotids)) fail("--userpkey and --spots must be positive integers.");

$versionImages = '/srv/app/www/versions/images';
$dbImages      = '/srv/app/www/dbimages';

line(($apply ? "APPLY" : "DRY RUN") . " (userpkey $userpkey)");
line();

$toFix = [];
foreach ($spotids as $spotid) {
    $spotname = $neodb->get_var("MATCH (s:Spot {id: $spotid}) WHERE s.userpkey = $userpkey RETURN s.name");
    if ($spotname === null) { line("Spot $spotid: not found for this user, skipped"); continue; }
    line("Spot $spotid ($spotname)");

    $rows = $neodb->get_results("MATCH (s:Spot {id: $spotid})-[:HAS_IMAGE]->(i:Image)
                                 WHERE s.userpkey = $userpkey AND i.userpkey = $userpkey
                                 RETURN i.id AS id, i.filename AS filename");
    if (empty($rows)) { line("  no images"); continue; }

    foreach ($rows as $r) {
        $imageid  = (int)$r->get('id');
        $filename = (string)$r->get('filename');
        if ($filename !== '' && file_exists("$dbImages/$filename")) {
            line("  [ok]      image $imageid already has file $filename");
        } elseif (file_exists("$versionImages/$imageid")) {
            line("  [RELINK]  image $imageid from versions/images/$imageid (" . filesize("$versionImages/$imageid") . " bytes)");
            $toFix[] = $imageid;
        } else {
            line("  [missing] image $imageid has no file and no version copy; the device must re-upload it");
        }
    }
}

line();
if (empty($toFix)) { line("Nothing to relink. Done."); exit(0); }
line(count($toFix) . " image(s) to relink.");

if (!$apply) {
    line("DRY RUN: no changes made. Re-run with --apply to write.");
    exit(0);
}

foreach ($toFix as $imageid) {
    $filename = $db->get_var("select nextval('image_seq')");
    if (!copy("$versionImages/$imageid", "$dbImages/$filename")) {
        fail("copy failed for image $imageid; stopping (earlier images stay relinked).");
    }
    $sha1 = sha1_file("$dbImages/$filename");
    $neodb->query("MATCH (i:Image {id: $imageid}) WHERE i.userpkey = $userpkey
                   SET i.filename = '$filename', i.origfilename = 'image.jpeg', i.imagesha1 = '$sha1'");
    line("  relinked image $imageid -> dbimages/$filename");
}

line();
line("Done.");
exit(0);
