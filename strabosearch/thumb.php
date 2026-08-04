<?php
/**
 * File: thumb.php
 * Description: StraboSearch image-results thumbnail service (§6.5.4) —
 *              GET /search/thumb.php?id=<image_hit_pkey>[&size=N].
 *              Looks up the strabosearch.image_hit row, applies the §5.5
 *              ACL for the SESSION identity (public OR owner OR accepted
 *              collaborator), resolves the subsystem-native source file,
 *              and serves a GD-downscaled JPEG through a lazy disk cache
 *              (the Phase 0.4 audit's answer to "no pre-generated
 *              thumbnails exist" for Field's 520k raw uploads).
 *
 *              Sources per subsystem:
 *                field — /dbimages/<filename>  (raw upload, extensionless,
 *                        99.98% JPEG; decoded via imagecreatefromstring
 *                        which sniffs the real format)
 *                micro — best-first candidate chain matching what the three
 *                        micro viewer tiers display (project id resolved by
 *                        (strabo_id, userpkey) — micro strabo_ids are NOT
 *                        unique across users): compositeThumbnails/<id>
 *                        (tiles tier), webThumbnails|webImages/<id> under
 *                        straboMicroFiles/ and straboMicroView/smzFiles/
 *                        (webImages tier), images/<id> original, and LAST
 *                        images/<id>.jpg — the legacy createProjectImages()
 *                        composite that degrades to a black label-only
 *                        canvas / 0-byte file when its uiImages/ base is
 *                        missing. Zero-byte candidates are skipped.
 *
 *              Cache: /var/www/searchthumbs/<pkey>_<size>.jpg, keyed by
 *              image_hit_pkey so an ACL re-check still runs every request
 *              (only the decode+resize is skipped).
 *
 * @package    StraboSpot Web Site — StraboSearch
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

session_start();

$userpkey = 0;
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === 'yes'
    && isset($_SESSION['userpkey']) && (int)$_SESSION['userpkey'] > 0) {
    $userpkey = (int)$_SESSION['userpkey'];
}
session_write_close();

// Buffer all output: any PHP warning/notice emitted before the image bytes
// (e.g. a db-layer trigger_error with display_errors on) would otherwise be
// prepended to the body and corrupt the image. discardOutput() drops it all
// just before we send real bytes.
ob_start();

include_once("../includes/config.inc.php");
include("../db.php");

$cacheDir = '/var/www/searchthumbs';

function discardOutput()
{
    while (ob_get_level() > 0) ob_end_clean();
}

function showNotFound()
{
    discardOutput();
    header('Content-Type: image/png');
    readfile($_SERVER['DOCUMENT_ROOT'] . "/includes/images/image-not-found.png");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1) showNotFound();

$size = isset($_GET['size']) ? (int)$_GET['size'] : 300;
if ($size < 50) $size = 50;
if ($size > 2000) $size = 2000;

$row = $db->get_row_prepared(
    "SELECT image_hit_pkey, image_id, image_subsystem, filename,
            project_id, project_userpkey, project_ispublic
     FROM strabosearch.image_hit WHERE image_hit_pkey = $1", array($id));
if (!$row) showNotFound();

// ---- §5.5 ACL: public OR owner OR accepted, non-disabled collaborator ----
$visible = ($row->project_ispublic === 't' || $row->project_ispublic === true);
if (!$visible && $userpkey > 0) {
    if ((int)$row->project_userpkey === $userpkey) {
        $visible = true;
    } else {
        $collab = $db->get_var_prepared(
            "SELECT count(*) FROM collaborators
             WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2
               AND collaborator_user_pkey = $3
               AND accepted = TRUE AND disabled = FALSE",
            array($row->project_id, (int)$row->project_userpkey, $userpkey));
        $visible = ((int)$collab > 0);
    }
}
if (!$visible) showNotFound();

// ---- lazy disk cache ------------------------------------------------------
$cacheFile = $cacheDir . '/' . (int)$row->image_hit_pkey . '_' . $size . '.jpg';
if (is_file($cacheFile)) {
    discardOutput();
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    readfile($cacheFile);
    exit();
}

// ---- resolve source file --------------------------------------------------
$docroot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$source = '';
if ($row->image_subsystem === 'field') {
    // filename is the /dbimages/ basename; forbid traversal.
    $base = basename((string)$row->filename);
    if ($base !== '') $source = $docroot . '/dbimages/' . $base;
} elseif ($row->image_subsystem === 'micro') {
    $ppkey = $db->get_var_prepared(
        "SELECT id FROM micro_projectmetadata
         WHERE strabo_id = $1 AND userpkey = $2 LIMIT 1",
        array($row->project_id, (int)$row->project_userpkey));
    if ($ppkey) {
        $pid = (int)$ppkey;
        $img = basename((string)$row->image_id);
        // Candidate sources, best first — mirrors what the three micro
        // viewer tiers actually display. images/<id>.jpg goes LAST: it is
        // the legacy createProjectImages() composite, which degrades to a
        // black label-only canvas (or a 0-byte file) whenever its
        // uiImages/ base was missing at generation time.
        $candidates = array(
            // tiles tier — prebuilt thumb the /microview/ viewer shows
            $docroot . '/straboMicroFiles/' . $pid . '/compositeThumbnails/' . $img,
            // webImages tier — straboMicroView sidebar thumb, then main
            // image; check both roots (smzFiles mirrors straboMicroFiles)
            $docroot . '/straboMicroFiles/' . $pid . '/webThumbnails/' . $img,
            $docroot . '/straboMicroView/smzFiles/' . $pid . '/webThumbnails/' . $img,
            $docroot . '/straboMicroFiles/' . $pid . '/webImages/' . $img,
            $docroot . '/straboMicroView/smzFiles/' . $pid . '/webImages/' . $img,
            // upload-time original (extensionless), then legacy composite
            $docroot . '/straboMicroFiles/' . $pid . '/images/' . $img,
            $docroot . '/straboMicroFiles/' . $pid . '/images/' . $img . '.jpg',
        );
        foreach ($candidates as $cand) {
            if (is_file($cand) && filesize($cand) > 0) { $source = $cand; break; }
        }
    }
}
if ($source === '' || !is_file($source)) showNotFound();

// ---- decode (format-sniffing), downscale, serve, cache --------------------
$bytes = file_get_contents($source);
if ($bytes === false) showNotFound();
$old = @imagecreatefromstring($bytes);
unset($bytes);
if ($old === false) showNotFound();

$w = imagesx($old);
$h = imagesy($old);
if ($w >= $h) {
    $nw = min($size, $w);
    $nh = (int)round(($h / $w) * $nw);
} else {
    $nh = min($size, $h);
    $nw = (int)round(($w / $h) * $nh);
}
if ($nw < 1) $nw = 1;
if ($nh < 1) $nh = 1;

$new = imagecreatetruecolor($nw, $nh);
imagecopyresampled($new, $old, 0, 0, 0, 0, $nw, $nh, $w, $h);
imagedestroy($old);

if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    @imagejpeg($new, $cacheFile, 82);
}

discardOutput();
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
if (is_file($cacheFile)) {
    readfile($cacheFile);
} else {
    imagejpeg($new, null, 82);
}
imagedestroy($new);
