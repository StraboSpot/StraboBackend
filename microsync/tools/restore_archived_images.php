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
include_once "../lib/MsConvert.php";

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

$restore = new MsRestoreArchivedImages($db, $say);
$report = $restore->run($only, $apply);

$dir = MsWorker::dataDir() . '/restore_images';
@mkdir($dir, 0775, true);
$file = "$dir/$only-" . ($apply ? 'apply' : 'dry-run') . '-' . gmdate('Ymd\THis\Z') . '.json';
file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$say("report: $file");
exit($report['status'] === 'ok' ? 0 : 1);

class MsRestoreArchivedImages {

	private $strabodb;
	private $db;
	private $say;

	public function __construct($strabodb, $say) {
		$this->strabodb = $strabodb;
		$this->db = new MsDb($strabodb);
		$this->say = $say;
	}

	private function say($msg) {
		call_user_func($this->say, $msg);
	}

	/** Restore one project; returns the report (status 'ok' or 'stopped'). */
	public function run($pid, $apply) {
		$report = array(
			'projectId' => $pid, 'mode' => $apply ? 'apply' : 'dry-run', 'at' => gmdate('Y-m-d\TH:i:s\Z'),
			'status' => 'ok', 'reason' => null, 'micrographs' => array(), 'restored' => 0, 'skipped' => 0,
		);
		$work = null;
		try {
			$this->restore($pid, $apply, $report, $work);
		} catch (Exception $e) {
			$report['status'] = 'stopped';
			$report['reason'] = $e->getMessage();
			$this->say('STOPPED: ' . $e->getMessage());
		} finally {
			if ($work !== null) {
				self::removeTree($work);
			}
		}
		return $report;
	}

	private function restore($pid, $apply, &$report, &$work) {
		$db = $this->db;
		$p = $db->row(
			"SELECT id, strabo_id, userpkey, sync_format, sync_state FROM strabomicro.micro_projectmetadata WHERE id = $1",
			array($pid));
		if ($p === null) {
			throw new Exception("no project $pid");
		}
		if ($p['sync_format'] !== 'entity' || $p['sync_state'] !== 'ready') {
			throw new Exception("project $pid is not a ready synced project ({$p['sync_format']}, {$p['sync_state']})");
		}
		$owner = (int)$p['userpkey'];

		$zipPath = MsConvert::archiveDir($pid) . '/project.zip';
		$zip = new ZipArchive();
		if (!is_file($zipPath) || $zip->open($zipPath) !== true) {
			throw new Exception("cannot open $zipPath");
		}
		$names = array();
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}
		$root = null;
		foreach ($names as $n) {
			if (preg_match('#^([^/]+)/project\.json$#', $n, $m)) {
				$root = $m[1];
				break;
			}
		}
		if ($root === null) {
			throw new Exception('the archive has no project.json');
		}
		if ($root !== $p['strabo_id']) {
			throw new Exception("the archive is project $root, not {$p['strabo_id']}");
		}
		$old = json_decode($zip->getFromName("$root/project.json"));
		if (!is_object($old)) {
			throw new Exception('the archived project.json is not readable');
		}
		$archived = array();
		foreach (isset($old->datasets) && is_array($old->datasets) ? $old->datasets : array() as $d) {
			foreach (isset($d->samples) && is_array($d->samples) ? $d->samples : array() as $s) {
				foreach (isset($s->micrographs) && is_array($s->micrographs) ? $s->micrographs : array() as $m) {
					if (is_object($m) && isset($m->id) && is_string($m->id)) {
						$archived[$m->id] = $m;
					}
				}
			}
		}
		$tilesOf = array(); // micrograph id => (relative name => zip entry)
		foreach ($names as $n) {
			if (preg_match('#^' . preg_quote($root, '#') . '/tiles/([^/]+)/(.+)$#', $n, $m) && substr($n, -1) !== '/') {
				$tilesOf[$m[1]][$m[2]] = $n;
			}
		}

		$live = $db->rows(
			"SELECT entity_id FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND entity_type = 'micrograph' AND deleted_at IS NULL ORDER BY entity_id",
			array($pid));
		$this->say(sprintf('project %d (%s): %d live micrograph(s), %d in the archive (%s)',
			$pid, $p['strabo_id'], count($live), count($archived), $apply ? 'APPLY' : 'dry run'));

		// Work folder on the project's own volume (blobs are renamed into place)
		$projectRoot = MsStore::filesRoot() . '/' . (int)$pid;
		$work = "$projectRoot/.restore-work-" . getmypid();
		if (!@mkdir($work, 0775, true) && !is_dir($work)) {
			throw new Exception("cannot create $work");
		}

		$plan = array(); // id => list of array(role, kind, file)
		foreach ($live as $r) {
			$mid = $r['entity_id'];
			$row = MsStore::entity($db, $pid, 'micrograph', $mid);
			$name = isset($row['body']->name) && is_string($row['body']->name) ? $row['body']->name : $mid;
			$entry = array('id' => $mid, 'name' => $name, 'action' => null, 'roles' => array());
			$refs = MsStore::refs($db, $pid, 'micrograph', $mid);
			$imageEntry = "$root/images/$mid";
			if (isset($refs['image'])) {
				$entry['action'] = 'skip: already has an image';
			} elseif ($zip->locateName($imageEntry) === false) {
				$entry['action'] = 'skip: no image in the archive';
			} else {
				$why = $this->sizeMismatch($row['body'], isset($archived[$mid]) ? $archived[$mid] : null, $zip, $imageEntry, $work);
				if ($why !== null) {
					$entry['action'] = "skip: $why";
				} else {
					$files = array();
					$files[] = array('image', 'image', $this->extract($zip, $imageEntry, "$work/image-$mid"));
					$thumb = "$root/compositeThumbnails/$mid";
					if (!isset($refs['thumbnail']) && $zip->locateName($thumb) !== false) {
						$files[] = array('thumbnail', 'thumbnail', $this->extract($zip, $thumb, "$work/thumb-$mid"));
					}
					if (!isset($refs['tiles']) && !empty($tilesOf[$mid])) {
						$files[] = array('tiles', 'tiles', $this->tileArchive($zip, $tilesOf[$mid], "$work/tiles-$mid"));
					}
					$plan[$mid] = $files;
					$entry['action'] = 'restore';
					foreach ($files as $f) {
						$entry['roles'][] = $f[0];
					}
				}
			}
			if ($entry['action'] === 'restore') {
				$report['restored']++;
			} else {
				$report['skipped']++;
			}
			$report['micrographs'][] = $entry;
			$this->say(sprintf('  %-36s %-30s %s%s', $mid, mb_strimwidth($name, 0, 30, '...'), $entry['action'],
				$entry['roles'] ? ' (' . implode(', ', $entry['roles']) . ')' : ''));
		}
		$zip->close();
		$this->say(sprintf('%d to restore, %d skipped', $report['restored'], $report['skipped']));
		if (!$apply || !$plan) {
			return;
		}

		// Blobs: content-addressed files in <project>/blobs/<sha256>
		$blobDir = "$projectRoot/blobs";
		if (!is_dir($blobDir) && !@mkdir($blobDir, 0775, true) && !is_dir($blobDir)) {
			throw new Exception("cannot create $blobDir");
		}
		$blobs = array(); // id => list of array(role, kind, sha, size)
		$bytes = 0;
		foreach ($plan as $mid => $files) {
			foreach ($files as $f) {
				list($role, $kind, $path) = $f;
				$sha = hash_file('sha256', $path);
				$size = filesize($path);
				$dest = "$blobDir/$sha";
				if (!is_file($dest) && !@rename($path, $dest)) {
					throw new Exception("cannot store the $role of $mid as a blob");
				}
				$blobs[$mid][] = array($role, $kind, $sha, $size);
				$bytes += $size;
			}
		}
		$report['blobBytes'] = $bytes;

		$db->begin();
		try {
			MsStore::lockProject($db, $pid);
			$ctx = new stdClass();
			$ctx->db = $db;
			$ctx->strabodb = $this->strabodb;
			$ctx->me = $owner;
			$ctx->pushId = null; // server job: every copy pulls it
			$head = 0;
			$set = 0;
			foreach ($blobs as $mid => $list) {
				$row = MsStore::entity($db, $pid, 'micrograph', $mid);
				if (!MsStore::isLive($row)) {
					continue; // deleted meanwhile
				}
				$refs = MsStore::refs($db, $pid, 'micrograph', $mid);
				foreach ($list as $b) {
					list($role, $kind, $sha, $size) = $b;
					if (isset($refs[$role])) {
						continue; // a copy uploaded one meanwhile: never replaced
					}
					$db->q(
						"INSERT INTO strabomicro.micro_blobs (project_id, sha256, size, kind, uploaded_by)
						 VALUES ($1, $2, $3, $4, $5) ON CONFLICT (project_id, sha256) DO NOTHING",
						array($pid, $sha, $size, $kind, $owner));
					$seq = MsBlobs::setRef($ctx, $pid, $row, $role, $sha);
					if ($seq !== null) {
						$head = max($head, $seq);
						$set++;
					}
					$row = MsStore::entity($db, $pid, 'micrograph', $mid); // the next ref's "before"
				}
			}
			if ($head > 0) {
				MsStore::bumpHead($db, $pid, $head, null);
			}
			$db->commit();
		} catch (Exception $e) {
			$db->rollback();
			throw $e;
		}
		$report['refsSet'] = $set;
		$this->say("$set ref(s) set, " . round($bytes / 1048576, 1) . ' MB of blobs');
		MsWorker::kick($pid);
		$this->say('worker asked to rebuild the web views');
	}

	/**
	 * Null when the live micrograph, the archived micrograph and the archived
	 * image all have the same size; else why not.
	 */
	private function sizeMismatch($body, $archivedMicrograph, $zip, $entry, $work) {
		$w = isset($body->width) ? (int)$body->width : 0;
		$h = isset($body->height) ? (int)$body->height : 0;
		if ($w <= 0 || $h <= 0) {
			return 'no stored size on the live micrograph';
		}
		if ($archivedMicrograph === null) {
			return 'not in the archived project';
		}
		$aw = isset($archivedMicrograph->width) ? (int)$archivedMicrograph->width : 0;
		$ah = isset($archivedMicrograph->height) ? (int)$archivedMicrograph->height : 0;
		if ($aw !== $w || $ah !== $h) {
			return "size changed since the archive ({$aw}x{$ah} then, {$w}x{$h} now)";
		}
		$tmp = "$work/probe";
		$this->extract($zip, $entry, $tmp);
		$info = @getimagesize($tmp);
		@unlink($tmp);
		if ($info === false) {
			return 'the archived image is not readable';
		}
		if ((int)$info[0] !== $w || (int)$info[1] !== $h) {
			return "the archived image is {$info[0]}x{$info[1]}, the micrograph {$w}x{$h}";
		}
		return null;
	}

	/** Copy one zip entry to $dest (streamed). Returns $dest. */
	private function extract($zip, $entry, $dest) {
		$in = $zip->getStream($entry);
		$out = $in === false ? false : @fopen($dest, 'wb');
		if ($in === false || $out === false) {
			throw new Exception("cannot extract $entry");
		}
		$ok = stream_copy_to_stream($in, $out) !== false;
		fclose($in);
		$ok = fclose($out) && $ok;
		if (!$ok) {
			throw new Exception("cannot extract $entry");
		}
		return $dest;
	}

	/**
	 * The micrograph's tile folder as the conversion stores it: a store-mode
	 * zip with fixed timestamps (MsSmz::writeStoreArchive). Returns its path.
	 */
	private function tileArchive($zip, $entries, $dest) {
		$dir = "$dest.d";
		$files = array();
		foreach ($entries as $rel => $entry) {
			if ($rel === '.blob' || strpos($rel, '..') !== false) {
				continue;
			}
			$path = "$dir/$rel";
			if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
				throw new Exception("cannot create " . dirname($path));
			}
			$files[$rel] = $this->extract($zip, $entry, $path);
		}
		if (MsSmz::writeStoreArchive($files, MsConvert::ARCHIVE_MTIME, $dest) === null) {
			throw new Exception("cannot write the tile archive $dest");
		}
		self::removeTree($dir);
		return $dest;
	}

	private static function removeTree($dir) {
		if (!is_dir($dir)) {
			return;
		}
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) {
			$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
		}
		@rmdir($dir);
	}
}
