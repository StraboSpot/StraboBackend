<?php
/**
 * File: restore_archived_images.php
 * Description: Give a synced StraboMicro project back the micrograph images
 *              it lost when a copy without images was adopted over an older
 *              upload (StraboMicro Sentry ELECTRON-39, project 950,
 *              2026-11-16). The adoption moved the upload to
 *              straboMicroFiles/_archive/<id>/project.zip (MsConvert::archiveDir);
 *              this tool reads that zip.
 *
 *              For every live micrograph that has no image ref and whose
 *              image is in the archive, it stores the archived image, its
 *              composite thumbnail and its tile folder as blobs and sets the
 *              image / thumbnail / tiles refs (MsBlobs::setRef), in one
 *              transaction under the project lock. The ref changes go into
 *              the change log with no push id, so every copy of the project
 *              pulls the images; then the worker rebuilds the web views.
 *              Refs a micrograph already has are never touched.
 *
 *              A micrograph is skipped when its stored size (width, height)
 *              differs from the archived micrograph's or from the archived
 *              image's pixels (the image may have changed since).
 *
 *              The logic is microsync/lib/MsRestoreImages.php, which
 *              MsAdopt::finish also runs (with --apply, in the background)
 *              after every adoption; this tool is for projects adopted
 *              before that, and for checking one by hand.
 *
 *              Default is a DRY RUN: the archive is read and every image
 *              checked; nothing is written.
 *
 *              Usage (as www-data inside strabo-php):
 *                php microsync/tools/restore_archived_images.php --only=950 [--apply]
 *              Report: microsync_data/restore_images/<id>-<mode>-<time>.json
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit();
}
set_time_limit(0);
chdir(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);

include_once "../../includes/config.inc.php";
include_once "../../db.php";
include_once "../../jwtmicrodb/strabomicroclass.php";
include_once "../lib/MsRestoreImages.php";

$apply = false;
$only = null;
foreach (array_slice($argv, 1) as $a) {
	if ($a === '--apply') {
		$apply = true;
	} elseif ($a === '--dry-run') {
		$apply = false;
	} elseif (preg_match('/^--only=([0-9]+)$/', $a, $m)) {
		$only = (int)$m[1];
	} else {
		$only = null;
		break;
	}
}
if ($only === null) {
	fwrite(STDERR, "usage: php restore_archived_images.php --only=<project id> [--apply]\n");
	exit(2);
}

$t0 = microtime(true);
$say = function ($msg) use ($t0) {
	printf("[%6.1f s] %s\n", microtime(true) - $t0, $msg);
};

$restore = new MsRestoreImages($db, $say);
$report = $restore->run($only, $apply);

$dir = MsWorker::dataDir() . '/restore_images';
@mkdir($dir, 0775, true);
$file = "$dir/$only-" . ($apply ? 'apply' : 'dry-run') . '-' . gmdate('Ymd\THis\Z') . '.json';
file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$say("report: $file");
exit($report['status'] === 'ok' ? 0 : 1);
