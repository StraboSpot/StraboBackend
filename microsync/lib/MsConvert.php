<?php
/**
 * File: MsConvert.php
 * Description: Rollout step 4 (design §4.8): converts a stored legacy
 *              StraboMicro project into the sync store in place (same
 *              micro_projectmetadata row, same id, same landing page).
 *
 *              Sources: the projectjson column (the raw upload; the
 *              project.json files on disk and inside project.zip carry the
 *              StraboSamples overlay) and the unpacked folder
 *              straboMicroFiles/<id>/ (images, thumbnails, attachments, tile
 *              pyramids, point counts), checked entry by entry against the
 *              central directory of project.zip.
 *
 *              Not converted (reported): JavaFX-format projects (project.zip
 *              holds uiImages/; they enter sync through the app's import),
 *              projects without project.zip, duplicate entities that differ.
 *
 *              One project:
 *                1. classify and read (no writes)
 *                2. predict every entry the streamed .smz will carry and
 *                   compare with project.zip (names, sizes, CRC-32; JSON by
 *                   content)
 *                3. in one transaction under the push lock: owner member
 *                   row, sync_format 'entity' / sync_state 'initializing',
 *                   entities through the push code (MsSync::applyChanges),
 *                   blob rows and refs (MsBlobs::setRef); assemble inside
 *                   the transaction and compare with the input.
 *                   Dry run: roll back here (nothing is kept).
 *                4. apply: commit, compare the real stream plan with the
 *                   zip, then ready + zip moved to _archive/<id>/ + journal
 *                   + MsWorker::build. Any failure undoes the project.
 *
 *              Blobs: originals are hard-linked into blobs/<sha> (same
 *              folder, no copy); tile archives are built from the tiles/
 *              folders with fixed timestamps (a rerun gives the same hash),
 *              and a .blob marker is written into the existing tile folder so
 *              the worker keeps it instead of unpacking again.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/MsHttp.php';
require_once __DIR__ . '/MsDb.php';
require_once __DIR__ . '/MsModel.php';
require_once __DIR__ . '/MsStore.php';
require_once __DIR__ . '/MsSync.php';
require_once __DIR__ . '/MsBlobs.php';
require_once __DIR__ . '/MsWorker.php';
require_once __DIR__ . '/MsSmz.php';

/** A project that cannot be converted; $reason is a short code for the report. */
class MsConvertStop extends Exception {
	public $reason;
	public $details;

	public function __construct($reason, $message, $details = array()) {
		parent::__construct($message);
		$this->reason = $reason;
		$this->details = $details;
	}
}

class MsConvert {

	/** Timestamp inside the conversion's tile archives (2026-01-01 UTC). */
	const ARCHIVE_MTIME = 1767225600;

	/** Zip entries a stream never carries (plus the app's .gitkeep placeholders). */
	private static $NOT_STREAMED = array('project.pdf', 'README.txt');

	/**
	 * Sample fields the StraboSamples overlay writes from the spine on every
	 * download (microdb/lib/sample_overlay.php). The zip's copy carries the
	 * spine as it was when that copy was written; the stream applies the
	 * current spine. So these are left out when comparing with the zip.
	 */
	private static $SPINE_FIELDS = array('sampleID', 'label', 'name', 'sampleDescription', 'sampleNotes',
		'latitude', 'longitude', 'materialType', 'mainSamplingPurpose');

	private $strabodb;
	private $db;
	private $log;

	/** Dry run of the JSON side only (dev rehearsal: most folders are prod-only). */
	public $jsonOnly = false;

	/** Tests only: callable($in) run just before the project is locked and re-checked. */
	public $beforeLockHook = null;

	/** True while replace() runs (P0-9): the store exists and is brought up to date. */
	private $replacing = false;

	/** $strabodb: the $db wrapper; $log: callable(string) for progress lines. */
	public function __construct($strabodb, $log = null) {
		$this->strabodb = $strabodb;
		$this->db = new MsDb($strabodb);
		$this->log = $log;
	}

	private function say($msg) {
		if ($this->log !== null) {
			call_user_func($this->log, $msg);
		}
	}

	public static function archiveDir($pid) {
		return MsStore::filesRoot() . '/_archive/' . (int)$pid;
	}

	/**
	 * Convert one project. $apply false = dry run (everything is checked,
	 * nothing is kept). Returns a report array:
	 *   id, status (converted | would_convert | skipped | failed),
	 *   reason, message, warnings[], stats{}, details{}.
	 */
	public function convert($pid, $apply) {
		$pid = (int)$pid;
		$report = array('id' => $pid, 'status' => null, 'reason' => null, 'message' => null,
			'warnings' => array(), 'stats' => array(), 'details' => array());
		$started = microtime(true);
		$in = null;
		try {
			if ($this->jsonOnly && $apply) {
				throw new MsConvertStop('error', 'json-only is a dry run');
			}
			$in = $this->read($pid, $report);
			if (!$this->jsonOnly) {
				$this->checkPredicted($in, $report);
			}
			$this->writeStore($in, $apply, $report);
			if ($apply) {
				$this->finish($in, $report);
				$report['status'] = 'converted';
			} else {
				$report['status'] = 'would_convert';
			}
		} catch (MsConvertStop $e) {
			$skip = in_array($e->reason, array('already_entity', 'no_row', 'no_folder', 'no_zip', 'javafx', 'empty'), true);
			$report['status'] = $skip ? 'skipped' : 'failed';
			$report['reason'] = $e->reason;
			$report['message'] = $e->getMessage();
			$report['details'] = $e->details;
		} catch (Exception $e) {
			$report['status'] = 'failed';
			$report['reason'] = 'error';
			$report['message'] = get_class($e) . ': ' . $e->getMessage();
		}
		if ($this->db->inTransaction()) {
			$this->db->rollback();
		}
		// Apply writes blobs before its transaction, so undo whenever it got that far.
		if ($apply && $in !== null && !empty($in['blobsMade']) && $report['status'] !== 'converted') {
			$this->undo($pid, $in);
			$report['details']['undone'] = true;
		}
		$report['stats']['seconds'] = round(microtime(true) - $started, 1);
		return $report;
	}

	/**
	 * P0-9: a legacy upload (old app) to a converted project whose owner is
	 * its only active member. The caller has already rebuilt the relational
	 * rows and the folder from the upload in place (the store's blobs/ kept)
	 * and holds the worker's build lock. This brings the store up to date
	 * with the upload: differences are logged as the owner's changes
	 * (creates, updates, moves, restores, deletes, file refs), the stream is
	 * checked against the uploaded project.zip, which is then removed (its
	 * content is in the store), and the views are rebuilt.
	 *
	 * If the upload cannot be taken into the store, the project falls back
	 * to legacy WITH the new upload (store rows removed, project.zip kept):
	 * the upload always succeeds and nothing the user sent is lost.
	 * Returns a report; status replaced | fell_back.
	 */
	public function replace($pid) {
		$pid = (int)$pid;
		$report = array('id' => $pid, 'status' => null, 'reason' => null, 'message' => null,
			'warnings' => array(), 'stats' => array(), 'details' => array());
		$started = microtime(true);
		$in = null;
		$this->replacing = true;
		try {
			$in = $this->read($pid, $report);
			$this->checkPredicted($in, $report);
			$this->writeStore($in, true, $report);
			$this->finish($in, $report);
			$report['status'] = 'replaced';
		} catch (Exception $e) {
			$report['status'] = 'fell_back';
			$report['reason'] = $e instanceof MsConvertStop ? $e->reason : 'error';
			$report['message'] = ($e instanceof MsConvertStop ? '' : get_class($e) . ': ') . $e->getMessage();
			if ($e instanceof MsConvertStop) {
				$report['details'] = $e->details;
			}
		}
		$this->replacing = false;
		if ($this->db->inTransaction()) {
			$this->db->rollback();
		}
		if ($report['status'] !== 'replaced') {
			$this->undo($pid, $in, true);
		}
		$report['stats']['seconds'] = round(microtime(true) - $started, 1);
		self::logUpload($pid, $report);
		return $report;
	}

	/** One line per P0-9 upload in _archive/<id>/uploads.log (small; same volume as the project). */
	private static function logUpload($pid, $report) {
		$dir = self::archiveDir($pid);
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		@file_put_contents("$dir/uploads.log", json_encode(array(
			'at' => gmdate('Y-m-d\TH:i:s\Z'), 'status' => $report['status'], 'reason' => $report['reason'],
			'message' => $report['message'], 'stats' => $report['stats'], 'warnings' => $report['warnings'],
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
	}

	// ------------------------------------------------------------------
	// 1. Read and classify (no writes)
	// ------------------------------------------------------------------

	private function read($pid, &$report) {
		$row = $this->db->row(
			"SELECT id, strabo_id, userpkey, name, sync_format, projectjson,
			        " . MsDb::iso('uploaddate') . " AS uploaddate
			   FROM strabomicro.micro_projectmetadata WHERE id = $1",
			array($pid));
		if ($row === null) {
			throw new MsConvertStop('no_row', 'no micro_projectmetadata row');
		}
		$report['straboId'] = $row['strabo_id'];
		$report['owner'] = (int)$row['userpkey'];
		$report['name'] = $row['name'];
		if ($this->replacing) {
			if ($row['sync_format'] !== 'entity') {
				throw new MsConvertStop('not_entity', 'not a converted project');
			}
		} elseif ($row['sync_format'] !== 'legacy') {
			throw new MsConvertStop('already_entity', 'already in the sync store');
		}
		if ($this->db->val("SELECT 1 FROM public.users WHERE pkey = $1", array((int)$row['userpkey'])) === null) {
			throw new MsConvertStop('no_owner', 'the owner (userpkey ' . (int)$row['userpkey'] . ') is not a user');
		}
		$root = MsStore::filesRoot() . '/' . $pid;
		if ($this->jsonOnly) {
			return $this->readJsonOnly($pid, $row, $root, $report);
		}
		if (!is_dir($root)) {
			throw new MsConvertStop('no_folder', "no folder $root");
		}
		$zip = "$root/project.zip";
		if (!is_file($zip)) {
			throw new MsConvertStop('no_zip', 'no project.zip');
		}
		clearstatcache(true, $zip);
		$zipStat = array('size' => filesize($zip), 'mtime' => filemtime($zip));
		$report['stats']['zipBytes'] = $zipStat['size'];

		$entries = MsZipReader::entries($zip);
		if ($entries === null) {
			throw new MsConvertStop('zip_unreadable', 'project.zip cannot be read');
		}
		// Entries sit under one root folder (the strabo id); key them by the rest.
		$zipFiles = array();
		$prefix = null;
		foreach ($entries as $e) {
			$slash = strpos($e['name'], '/');
			$top = $slash === false ? '' : substr($e['name'], 0, $slash);
			if ($prefix === null) {
				$prefix = $top;
			}
			if ($slash === false || $top !== $prefix) {
				throw new MsConvertStop('zip_layout', 'project.zip entries are not under one root folder',
					array('entry' => $e['name']));
			}
			$zipFiles[substr($e['name'], $slash + 1)] = $e;
		}
		foreach ($zipFiles as $rel => $e) {
			if (strpos($rel, 'uiImages/') === 0) {
				throw new MsConvertStop('javafx', 'JavaFX-format project (uiImages/ in project.zip); converts through the app');
			}
		}
		if (!isset($zipFiles['project.json'])) {
			throw new MsConvertStop('zip_layout', 'project.zip has no project.json');
		}

		$raw = json_decode((string)$row['projectjson']);
		if (!is_object($raw)) {
			throw new MsConvertStop('bad_json', 'projectjson column is not a JSON object');
		}
		if (!isset($raw->id) || $raw->id !== $row['strabo_id']) {
			throw new MsConvertStop('bad_json', 'project id in projectjson does not match strabo_id',
				array('jsonId' => isset($raw->id) ? $raw->id : null));
		}
		$norm = $this->normalize($raw, $report);

		// Point counts live in their own files.
		$pointCounts = array();
		$micrographs = self::micrographs($norm);
		foreach ((array)@scandir("$root/point-counts") as $f) {
			if (substr($f, -5) !== '.json') {
				continue;
			}
			$pc = json_decode((string)file_get_contents("$root/point-counts/$f"));
			if (!is_object($pc) || !isset($pc->id, $pc->micrographId) || !MsModel::isId($pc->id)) {
				throw new MsConvertStop('bad_point_count', "point-counts/$f has no usable id / micrographId");
			}
			if ($f !== MsSmz::safeName($pc->id) . '.json') {
				throw new MsConvertStop('bad_point_count', "point-counts/$f is not named after its id {$pc->id}");
			}
			if (!isset($micrographs[$pc->micrographId])) {
				throw new MsConvertStop('bad_point_count', "point-counts/$f belongs to a micrograph not in the project",
					array('micrographId' => $pc->micrographId));
			}
			$pointCounts[$pc->id] = $pc;
		}

		return array(
			'pid' => $pid, 'row' => $row, 'root' => $root, 'zip' => $zip, 'zipStat' => $zipStat,
			'zipFiles' => $zipFiles, 'json' => $norm, 'micrographs' => $micrographs,
			'pointCounts' => $pointCounts, 'files' => $this->filePlan($root, $norm, $micrographs, $report),
		);
	}

	private function readJsonOnly($pid, $row, $root, &$report) {
		$raw = json_decode((string)$row['projectjson']);
		if (!is_object($raw) || !isset($raw->id) || $raw->id !== $row['strabo_id']) {
			throw new MsConvertStop('bad_json', 'projectjson is not an object with the project id');
		}
		$norm = $this->normalize($raw, $report);
		return array('pid' => $pid, 'row' => $row, 'root' => $root, 'zip' => null, 'zipStat' => null,
			'zipFiles' => array(), 'json' => $norm, 'micrographs' => self::micrographs($norm),
			'pointCounts' => array(), 'files' => array());
	}

	/**
	 * Same shape the push path expects: every child collection an array,
	 * per-user fields gone, duplicates (same type and id) collapsed when
	 * identical and under the same parent. Differing duplicates stop the
	 * project (P0-5). Works on a copy.
	 */
	private function normalize($raw, &$report) {
		$j = json_decode(json_encode($raw));
		$seen = array();
		$dedupes = 0;
		$filled = 0;
		$walk = function ($obj, $type, $parentKey) use (&$walk, &$seen, &$dedupes, &$filled) {
			foreach (MsModel::perUserFields($type) as $f) {
				unset($obj->$f);
			}
			foreach (MsModel::$CHILD_KEYS[$type] as $key => $childType) {
				if (!isset($obj->$key) || !is_array($obj->$key)) {
					if (isset($obj->$key) && $obj->$key !== null) {
						throw new MsConvertStop('bad_json', "\"$key\" of $type {$obj->id} is not a list");
					}
					$filled++;
					$obj->$key = array();
				}
				$list = array();
				foreach ($obj->$key as $child) {
					if (!is_object($child) || !isset($child->id) || !MsModel::isId($child->id)) {
						throw new MsConvertStop('bad_id', "a $childType under $type {$obj->id} has no usable id");
					}
					$k = MsModel::key($childType, $child->id);
					$me = MsModel::key($type, $obj->id);
					if (isset($seen[$k])) {
						if ($seen[$k]['parent'] === $me && self::same($seen[$k]['obj'], $child)) {
							$dedupes++;
							continue;
						}
						throw new MsConvertStop('duplicate_differs', "$childType {$child->id} appears twice with different content",
							array('first' => $seen[$k]['parent'], 'second' => $me));
					}
					$seen[$k] = array('parent' => $me, 'obj' => json_decode(json_encode($child)));
					$walk($child, $childType, $me);
					$list[] = $child;
				}
				$obj->$key = $list;
			}
		};
		$walk($j, 'project', null);
		if ($dedupes > 0) {
			$report['warnings'][] = "$dedupes identical duplicate entities collapsed";
		}
		$report['stats']['emptyListsFilled'] = $filled;
		return $j;
	}

	/** micrograph id => micrograph object (normalized project). */
	private static function micrographs($j) {
		$out = array();
		foreach ($j->datasets as $d) {
			foreach ($d->samples as $s) {
				foreach ($s->micrographs as $m) {
					$out[$m->id] = $m;
				}
			}
		}
		return $out;
	}

	/**
	 * Which files become blobs: role, entity, source path (or tile folder).
	 * Missing sources are warnings; the zip comparison decides whether
	 * that loses anything.
	 */
	private function filePlan($root, $j, $micrographs, &$report) {
		$plan = array();
		$attach = function ($type, $obj) use ($root, &$plan, &$report) {
			if (!isset($obj->associatedFiles) || !is_array($obj->associatedFiles)) {
				return;
			}
			foreach ($obj->associatedFiles as $af) {
				$name = is_object($af) && isset($af->fileName) && is_string($af->fileName) ? $af->fileName : '';
				if ($name === '') {
					continue;
				}
				$role = "associated_file:$name";
				if (MsBlobs::roleKind($role) === null) {
					$report['warnings'][] = "attachment name not storable: $name";
					continue;
				}
				$path = "$root/associatedFiles/" . MsSmz::safeName($name);
				if (!is_file($path)) {
					$report['warnings'][] = "attachment missing on disk: $name";
					continue;
				}
				$plan[] = array('type' => $type, 'id' => $obj->id, 'role' => $role, 'kind' => 'associated_file', 'path' => $path);
			}
		};
		foreach ($micrographs as $mid => $m) {
			$safe = MsSmz::safeName($mid);
			foreach (array('image' => 'images', 'thumbnail' => 'compositeThumbnails') as $role => $folder) {
				$path = "$root/$folder/$safe";
				if (is_file($path)) {
					$plan[] = array('type' => 'micrograph', 'id' => $mid, 'role' => $role, 'kind' => $role, 'path' => $path);
				} elseif ($role === 'image') {
					$report['warnings'][] = "image missing on disk: $mid";
				}
			}
			foreach (array('tiles' => 'tiles', 'tiles_affine' => 'tilesAffine') as $role => $folder) {
				$dir = "$root/$folder/$safe";
				if (is_dir($dir)) {
					$plan[] = array('type' => 'micrograph', 'id' => $mid, 'role' => $role, 'kind' => $role, 'dir' => $dir);
				}
			}
			$attach('micrograph', $m);
			foreach ($m->spots as $sp) {
				$attach('spot', $sp);
			}
		}
		// Files on disk that no entity names are left out of the stream (and
		// the worker would remove them); the zip comparison reports them.
		return $plan;
	}

	/** Files of a tile folder: relative name => path, sorted; the worker's .blob marker left out. */
	private static function tileFiles($dir) {
		$out = array();
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if (!$f->isFile()) {
				continue;
			}
			$rel = substr($f->getPathname(), strlen($dir) + 1);
			if ($rel === '.blob') {
				continue;
			}
			$out[$rel] = $f->getPathname();
		}
		ksort($out, SORT_STRING);
		return $out;
	}

	// ------------------------------------------------------------------
	// 2. Predicted stream vs project.zip
	// ------------------------------------------------------------------

	private function checkPredicted($in, &$report) {
		$want = array();
		$files = array();
		foreach ($in['files'] as $f) {
			$safe = MsSmz::safeName($f['id']);
			if (isset($f['dir'])) {
				$folder = $f['role'] === 'tiles' ? 'tiles' : 'tilesAffine';
				foreach (self::tileFiles($f['dir']) as $rel => $path) {
					$want["$folder/$safe/$rel"] = array('path' => $path);
				}
			} elseif ($f['kind'] === 'associated_file') {
				$want['associatedFiles/' . MsSmz::safeName(substr($f['role'], strlen('associated_file:')))] = array('path' => $f['path']);
			} else {
				$want[($f['role'] === 'image' ? 'images/' : 'compositeThumbnails/') . $safe] = array('path' => $f['path']);
			}
		}
		foreach ($in['pointCounts'] as $id => $pc) {
			$want['point-counts/' . MsSmz::safeName($id) . '.json'] = array('json' => $pc);
		}
		$overlaid = json_decode(json_encode($in['json']));
		require_once dirname(__DIR__, 2) . '/microdb/lib/sample_overlay.php';
		micro_sample_overlay_apply($overlaid, $this->strabodb, (int)$in['row']['userpkey']);
		$want['project.json'] = array('json' => $overlaid);

		$diff = $this->compareWithZip($in, $want, function ($w) {
			return array(filesize($w['path']), (int)hexdec(hash_file('crc32b', $w['path'])));
		});
		$report['stats']['streamEntries'] = count($want);
		if ($diff !== null) {
			throw new MsConvertStop('zip_mismatch', 'the stream would not match project.zip', $diff);
		}
	}

	/**
	 * Compare wanted entries (rel name => spec) with the zip. $sizeCrc maps a
	 * file spec to array(size, crc). JSON entries compare by content after
	 * the same normalization. Returns null when equal, else a summary.
	 */
	private function compareWithZip($in, $want, $sizeCrc) {
		$zipFiles = $in['zipFiles'];
		foreach (self::$NOT_STREAMED as $n) {
			unset($zipFiles[$n]);
		}
		foreach (array_keys($zipFiles) as $rel) {
			if (basename($rel) === '.gitkeep') {
				unset($zipFiles[$rel]);
			}
		}
		$missing = array_keys(array_diff_key($zipFiles, $want)); // in zip, not in stream
		$extra = array_keys(array_diff_key($want, $zipFiles));   // in stream, not in zip
		$differ = array();
		$za = null;
		foreach (array_intersect_key($want, $zipFiles) as $rel => $w) {
			$z = $zipFiles[$rel];
			if (isset($w['json'])) {
				if ($za === null) {
					$za = new ZipArchive();
					if ($za->open($in['zip']) !== true) {
						throw new MsConvertStop('zip_unreadable', 'project.zip cannot be opened');
					}
				}
				$prefix = substr($za->getNameIndex(0), 0, strpos($za->getNameIndex(0), '/') + 1);
				$zj = json_decode((string)$za->getFromName($prefix . $rel));
				if ($rel === 'project.json' && is_object($zj)) {
					$tmp = array('warnings' => array(), 'stats' => array());
					try {
						$zj = $this->normalize($zj, $tmp);
					} catch (MsConvertStop $e) {
						$zj = null;
					}
				}
				$wj = $w['json'];
				if ($rel === 'project.json') {
					$zj = self::withoutSpine($zj);
					$wj = self::withoutSpine($wj);
				}
				if (!($this->replacing ? self::sameLoose($zj, $wj) : self::same($zj, $wj))) {
					$differ[] = $rel;
				}
				continue;
			}
			list($size, $crc) = $sizeCrc($w);
			if ($size !== $z['usize'] || $crc !== $z['crc']) {
				$differ[] = $rel;
			}
		}
		if ($za !== null) {
			$za->close();
		}
		if (!$missing && !$extra && !$differ) {
			return null;
		}
		$cut = function ($l) {
			return array('count' => count($l), 'first' => array_slice($l, 0, 10));
		};
		return array('onlyInZip' => $cut($missing), 'onlyInStream' => $cut($extra), 'different' => $cut($differ));
	}

	// ------------------------------------------------------------------
	// 3. Entity store (one transaction)
	// ------------------------------------------------------------------

	/** Dry run rolls the transaction back; apply commits it. */
	private function writeStore(&$in, $apply, &$report) {
		$db = $this->db;
		$pid = $in['pid'];
		$owner = (int)$in['row']['userpkey'];

		// Blobs first, outside the transaction (apply only): hashes, hard
		// links, tile archives. Undo removes blobs/ if anything fails.
		$blobs = array(); // index in files => array(sha, size, kind)
		if ($apply) {
			$blobs = $this->makeBlobs($in, $report);
		}

		if ($this->beforeLockHook !== null) {
			call_user_func($this->beforeLockHook, $in);
		}
		$db->begin();
		MsStore::lockProject($db, $pid);
		$now = $db->row(
			"SELECT sync_format, " . MsDb::iso('uploaddate') . " AS uploaddate
			   FROM strabomicro.micro_projectmetadata WHERE id = $1 FOR UPDATE",
			array($pid));
		$zipChanged = false;
		if ($in['zip'] !== null) {
			clearstatcache(true, $in['zip']);
			$zipChanged = !is_file($in['zip']) || filesize($in['zip']) !== $in['zipStat']['size']
				|| filemtime($in['zip']) !== $in['zipStat']['mtime'];
		}
		$format = $this->replacing ? 'entity' : 'legacy';
		if ($now === null || $now['sync_format'] !== $format || $now['uploaddate'] !== $in['row']['uploaddate'] || $zipChanged) {
			throw new MsConvertStop('changed', 'the project changed during conversion (new upload?); run again later');
		}
		if (!$this->replacing) {
			$db->q(
				"UPDATE strabomicro.micro_projectmetadata
				    SET sync_format = 'entity', sync_state = 'initializing', head_seq = 0,
				        views_dirty_since = NULL, views_built_at = NULL
				  WHERE id = $1",
				array($pid));
			$db->q(
				"INSERT INTO strabomicro.micro_members (project_id, user_pkey, role, state, invited_by, responded_at)
				 VALUES ($1, $2, 'owner', 'active', $2, now())",
				array($pid, $owner));
		}

		$ctx = new stdClass();
		$ctx->db = $db;
		$ctx->strabodb = $this->strabodb;
		$ctx->me = $owner;
		$ctx->pushId = null; // server job
		$ctx->project = array('id' => $pid, 'strabo_id' => $in['row']['strabo_id'], 'role' => 'owner',
			'member_state' => 'active', 'head_seq' => 0);

		$changes = self::decompose($in['json']);
		foreach ($in['pointCounts'] as $id => $pc) {
			$changes[] = array('op' => 'create', 'type' => 'point_count', 'id' => $id,
				'parentType' => 'micrograph', 'parentId' => $pc->micrographId, 'body' => $pc);
		}
		$results = $this->replacing
			? $this->applyReplace($ctx, $pid, $changes, $report)
			: MsSync::applyChanges($ctx, $pid, json_decode(json_encode($changes)));
		$bad = array();
		foreach ($results as $r) {
			if ($r['status'] !== 'accepted') {
				$bad[] = $r;
			}
		}
		$report['stats']['entities'] = count($changes);
		$report['stats']['files'] = count($in['files']);
		if ($bad) {
			throw new MsConvertStop('push_rejected', count($bad) . ' entities were not accepted by the push rules',
				array('count' => count($bad), 'first' => array_slice($bad, 0, 10)));
		}

		if ($apply) {
			foreach ($in['files'] as $i => $f) {
				if (!isset($blobs[$i])) {
					continue;
				}
				list($sha, $size, $kind) = $blobs[$i];
				$db->q(
					"INSERT INTO strabomicro.micro_blobs (project_id, sha256, size, kind, uploaded_by)
					 VALUES ($1, $2, $3, $4, $5) ON CONFLICT (project_id, sha256) DO NOTHING",
					array($pid, $sha, $size, $kind, $owner));
				$row = MsStore::entity($db, $pid, $f['type'], $f['id']);
				MsBlobs::setRef($ctx, $pid, $row, $f['role'], $sha);
			}
			$report['stats']['refs'] = count($blobs);
			if ($this->replacing) {
				// Refs the upload no longer has (on live entities).
				$want = array();
				foreach ($in['files'] as $f) {
					$want[$f['type'] . ':' . $f['id'] . '|' . $f['role']] = true;
				}
				$gone = 0;
				foreach ($db->rows(
					"SELECT r.entity_type, r.entity_id, r.role
					   FROM strabomicro.micro_blob_refs r
					   JOIN strabomicro.micro_entities e
					     ON e.project_id = r.project_id AND e.entity_type = r.entity_type AND e.entity_id = r.entity_id
					  WHERE r.project_id = $1 AND e.deleted_at IS NULL", array($pid)) as $r) {
					if (!isset($want[$r['entity_type'] . ':' . $r['entity_id'] . '|' . $r['role']])) {
						MsBlobs::setRef($ctx, $pid, MsStore::entity($db, $pid, $r['entity_type'], $r['entity_id']), $r['role'], null);
						$gone++;
					}
				}
				$report['stats']['refsRemoved'] = $gone;
			}
		}

		$head = (int)$db->val("SELECT COALESCE(MAX(seq), 0) FROM strabomicro.micro_changes WHERE project_id = $1", array($pid));
		$db->q("UPDATE strabomicro.micro_projectmetadata SET head_seq = $2 WHERE id = $1", array($pid, $head));

		// Round trip inside the transaction: the store gives back the input.
		$a = MsWorker::assemble($db, $pid, $in['row']['strabo_id']);
		$same = $this->replacing ? 'sameLoose' : 'same';
		if ($a === null || !self::$same(json_decode($a['json']), $in['json'])) {
			$diff = $a === null ? 'no project entity' : self::firstDifference(json_decode($a['json'], true), json_decode(json_encode($in['json']), true));
			throw new MsConvertStop('round_trip', 'the assembled project differs from the input', array('at' => $diff));
		}
		foreach ($in['pointCounts'] as $id => $pc) {
			if (!isset($a['pointCounts'][$id]) || !self::$same($a['pointCounts'][$id], $pc)) {
				throw new MsConvertStop('round_trip', "point count $id differs after the round trip");
			}
		}

		if (!$apply) {
			$db->rollback();
			return;
		}
		$db->commit();
	}

	/** Hash (and link / build) every planned file. Returns index => array(sha, size, kind). */
	private function makeBlobs(&$in, &$report) {
		$dir = $in['root'] . '/blobs';
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new MsConvertStop('io', "cannot create $dir");
		}
		$in['blobsMade'] = true;
		$out = array();
		$bytes = 0;
		foreach ($in['files'] as $i => $f) {
			if (isset($f['dir'])) {
				$tmp = "$dir/.convert-" . getmypid() . '.zip';
				if (MsSmz::writeStoreArchive(self::tileFiles($f['dir']), self::ARCHIVE_MTIME, $tmp) === null) {
					@unlink($tmp);
					throw new MsConvertStop('io', "cannot write the tile archive for {$f['id']}");
				}
				$sha = hash_file('sha256', $tmp);
				$size = filesize($tmp);
				$dest = "$dir/$sha";
				if (is_file($dest)) {
					@unlink($tmp);
				} elseif (!@rename($tmp, $dest)) {
					@unlink($tmp);
					throw new MsConvertStop('io', "cannot store the tile archive for {$f['id']}");
				}
				// The folder already holds exactly this archive's files.
				@file_put_contents($f['dir'] . '/.blob', $sha);
			} else {
				$sha = hash_file('sha256', $f['path']);
				$size = filesize($f['path']);
				$dest = "$dir/$sha";
				if (!is_file($dest) && !@link($f['path'], $dest) && !@copy($f['path'], $dest)) {
					throw new MsConvertStop('io', "cannot store {$f['path']} as a blob");
				}
			}
			$bytes += $size;
			$out[$i] = array($sha, $size, $f['kind']);
		}
		$report['stats']['blobBytes'] = $bytes;
		return $out;
	}

	// ------------------------------------------------------------------
	// 4. Finish (apply) and undo
	// ------------------------------------------------------------------

	private function finish($in, &$report) {
		$db = $this->db;
		$pid = $in['pid'];

		// The real stream, entry by entry, against the zip.
		$plan = MsSmz::plan($this->strabodb, $pid, true);
		if ($plan === null) {
			throw new MsConvertStop('stream', 'the stream cannot be assembled');
		}
		$want = array();
		foreach ($plan['entries'] as $e) {
			$rel = substr($e['name'], strpos($e['name'], '/') + 1);
			if (substr($rel, -5) === '.json' && isset($e['data'])) {
				$want[$rel] = array('json' => json_decode($e['data']));
			} else {
				$want[$rel] = array('size' => $e['usize'], 'crc' => $e['crc']);
			}
		}
		$diff = $this->compareWithZip($in, $want, function ($w) {
			return array($w['size'], $w['crc']);
		});
		if ($diff !== null) {
			throw new MsConvertStop('zip_mismatch', 'the stream does not match project.zip', $diff);
		}

		// A legacy upload that slipped in wins: leave the project alone.
		clearstatcache(true, $in['zip']);
		if (!is_file($in['zip']) || filesize($in['zip']) !== $in['zipStat']['size']
			|| filemtime($in['zip']) !== $in['zipStat']['mtime']) {
			throw new MsConvertStop('changed', 'project.zip changed during conversion; run again later');
		}

		if ($this->replacing) {
			// The upload's content is in the store now; its zip would shadow the stream.
			if (!@unlink($in['zip'])) {
				throw new MsConvertStop('io', 'cannot remove the uploaded project.zip');
			}
			$db->q("UPDATE strabomicro.micro_projectmetadata SET views_dirty_since = now() WHERE id = $1", array($pid));
			$this->buildViews($pid, $report);
			return;
		}

		$arch = self::archiveDir($pid);
		if (!is_dir($arch) && !@mkdir($arch, 0775, true) && !is_dir($arch)) {
			throw new MsConvertStop('io', "cannot create $arch");
		}
		if (is_file("$arch/project.zip")) {
			throw new MsConvertStop('io', "$arch/project.zip already exists");
		}
		$journal = array(
			'tool' => 'microsync/tools/convert.php',
			'headSeq' => (int)$db->val("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid)),
			'convertedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'projectId' => $pid,
			'straboId' => $in['row']['strabo_id'],
			'owner' => (int)$in['row']['userpkey'],
			'uploadDate' => $in['row']['uploaddate'],
			'zip' => array('bytes' => $in['zipStat']['size'], 'mtime' => gmdate('Y-m-d\TH:i:s\Z', $in['zipStat']['mtime'])),
			'stats' => $report['stats'],
			'warnings' => $report['warnings'],
			'rollback' => 'feed the current streamed .smz into the legacy upload path (keeps later edits); this archive is the exact pre-conversion state',
		);
		if (@file_put_contents("$arch/journal.json", json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
			throw new MsConvertStop('io', "cannot write $arch/journal.json");
		}
		if (!@rename($in['zip'], "$arch/project.zip")) {
			throw new MsConvertStop('io', 'cannot move project.zip to the archive');
		}

		$db->q(
			"UPDATE strabomicro.micro_projectmetadata
			    SET sync_state = 'ready', views_dirty_since = now()
			  WHERE id = $1",
			array($pid));
		$this->say("  project $pid: store ready, zip archived, building views");
		$this->buildViews($pid, $report);
	}

	/** Rebuild the derived views now (session advisory locks nest, so a caller may hold it too). */
	private function buildViews($pid, &$report) {
		$db = $this->db;
		if (!MsWorker::tryLock($db, 'build', $pid)) {
			$report['warnings'][] = 'worker busy; the cron sweep builds the views';
			return;
		}
		try {
			$result = MsWorker::build($this->strabodb, $db, $pid);
		} finally {
			MsWorker::unlock($db, 'build', $pid);
		}
		if ($result !== 'built') {
			$report['warnings'][] = "worker: $result (the store is kept; the sweep retries)";
		}
	}

	/**
	 * Back to legacy with the archived zip, for a converted project nobody
	 * has changed since (one member, head_seq as journaled). Otherwise the
	 * rollback is the streamed .smz through the legacy upload (P0-12),
	 * which keeps later edits. Returns null on success or the refusal.
	 */
	public function revert($pid) {
		$pid = (int)$pid;
		$row = $this->db->row(
			"SELECT sync_format, head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
		if ($row === null || $row['sync_format'] !== 'entity') {
			return 'not a converted project';
		}
		$j = json_decode((string)@file_get_contents(self::archiveDir($pid) . '/journal.json'), true);
		if (!is_array($j) || !isset($j['headSeq']) || !is_file(self::archiveDir($pid) . '/project.zip')) {
			return 'no conversion journal and archived project.zip';
		}
		if ((int)$row['head_seq'] !== (int)$j['headSeq']) {
			return 'changed since conversion; use the streamed .smz through the legacy upload instead';
		}
		$others = (int)$this->db->val(
			"SELECT count(*) FROM strabomicro.micro_members WHERE project_id = $1 AND role <> 'owner'", array($pid));
		if ($others > 0) {
			return 'has members besides the owner';
		}
		$this->undo($pid, null);
		return null;
	}

	/**
	 * Put a project back to legacy: store rows, blobs/, tile markers, and
	 * (conversion) the archived zip back in place. $fallback (P0-9): the new
	 * upload's project.zip stays, the archive and its logs are left alone.
	 */
	private function undo($pid, $in, $fallback = false) {
		$db = $this->db;
		if ($db->inTransaction()) {
			$db->rollback();
		}
		$db->begin();
		MsStore::lockProject($db, $pid);
		foreach (array('micro_blob_refs', 'micro_blobs', 'micro_changes', 'micro_entities', 'micro_pushes', 'micro_members') as $t) {
			$db->q("DELETE FROM strabomicro.$t WHERE project_id = $1", array($pid));
		}
		$db->q(
			"UPDATE strabomicro.micro_projectmetadata
			    SET sync_format = 'legacy', sync_state = 'ready', head_seq = 0,
			        views_dirty_since = NULL, views_built_at = NULL
			  WHERE id = $1",
			array($pid));
		$db->commit();

		$root = MsStore::filesRoot() . '/' . (int)$pid;
		$arch = self::archiveDir($pid);
		if (!$fallback) {
			if (!is_file("$root/project.zip") && is_file("$arch/project.zip")) {
				@rename("$arch/project.zip", "$root/project.zip");
			}
			@unlink("$arch/journal.json");
			@unlink("$arch/uploads.log");
			@rmdir($arch);
		}
		foreach (array('tiles', 'tilesAffine') as $folder) {
			foreach ((array)@scandir("$root/$folder") as $mid) {
				if ($mid !== '.' && $mid !== '..' && is_file("$root/$folder/$mid/.blob")) {
					@unlink("$root/$folder/$mid/.blob");
				}
			}
		}
		foreach ((array)@scandir("$root/blobs") as $f) {
			if ($f !== '.' && $f !== '..') {
				@unlink("$root/blobs/$f");
			}
		}
		@rmdir("$root/blobs");
		$this->say("  project $pid: undone, back to legacy");
	}

	// ------------------------------------------------------------------
	// Replace mode (P0-9): store vs upload, as the owner's changes
	// ------------------------------------------------------------------

	/**
	 * Bring the store to $changes (creates of the whole upload, parents
	 * first) through the push code, one change at a time:
	 *   1. in upload order: create what is new, restore what was deleted,
	 *      update what differs (fields, a new parent); point counts that
	 *      moved micrograph are deleted and created again
	 *   2. delete what the upload no longer has (parents first; a child
	 *      already tombstoned by its parent's cascade is fine)
	 *   3. child order wherever it differs (children now exist)
	 * Returns results like a push (only failures matter to the caller).
	 */
	private function applyReplace($ctx, $pid, $changes, &$report) {
		$db = $ctx->db;
		$results = array();
		$counts = array('create' => 0, 'update' => 0, 'restore' => 0, 'delete' => 0, 'order' => 0);
		$one = function ($c) use ($ctx, $pid, &$results, &$counts) {
			$r = MsSync::applyChanges($ctx, $pid, array(json_decode(json_encode($c))));
			$results[] = $r[0];
			if ($r[0]['status'] === 'accepted') {
				$counts[$c['op'] === 'update' && !isset($c['fields']) && !isset($c['parentId']) ? 'order' : $c['op']]++;
			}
			return $r[0];
		};

		$wanted = array();
		foreach ($changes as $c) {
			$wanted[MsModel::key($c['type'], $c['id'])] = $c;
			$row = MsStore::entity($db, $pid, $c['type'], $c['id']);
			$ptype = isset($c['parentType']) ? $c['parentType'] : null;
			$ppid = isset($c['parentId']) ? $c['parentId'] : null;
			if ($row !== null && $c['type'] === 'point_count' && $row['parent_id'] !== $ppid) {
				if (MsStore::isLive($row)) {
					$one(array('op' => 'delete', 'type' => 'point_count', 'id' => $c['id'], 'baseVersion' => $row['version']));
				}
				$results[] = array('type' => 'point_count', 'id' => $c['id'], 'status' => 'invalid', 'reason' => 'moved',
					'message' => 'a point count session that changed micrograph cannot keep its id');
				continue;
			}
			if ($row === null) {
				$one($c);
				continue;
			}
			if (!MsStore::isLive($row)) {
				$r = $one(array('op' => 'restore', 'type' => $c['type'], 'id' => $c['id']));
				if ($r['status'] !== 'accepted') {
					continue;
				}
				$row = MsStore::entity($db, $pid, $c['type'], $c['id']);
			}
			$upd = array('op' => 'update', 'type' => $c['type'], 'id' => $c['id'], 'baseVersion' => $row['version']);
			$fields = self::fieldDiff($c['type'], $row['body'], $c['body']);
			if ($fields) {
				$upd['fields'] = $fields;
			}
			if ($ptype !== null && $row['parent_id'] !== $ppid) {
				$upd['parentType'] = $ptype;
				$upd['parentId'] = $ppid;
			}
			if (isset($upd['fields']) || isset($upd['parentId'])) {
				$one($upd);
			}
		}

		// 2. Deletes, parents first.
		$depth = array('dataset' => 1, 'tag' => 1, 'group' => 1, 'preset' => 1, 'sample' => 2,
			'micrograph' => 3, 'spot' => 4, 'point_count' => 4);
		$live = $db->rows(
			"SELECT entity_type, entity_id, version FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND deleted_at IS NULL AND entity_type <> 'project'",
			array($pid));
		usort($live, function ($a, $b) use ($depth) {
			return $depth[$a['entity_type']] - $depth[$b['entity_type']];
		});
		foreach ($live as $e) {
			if (isset($wanted[MsModel::key($e['entity_type'], $e['entity_id'])])) {
				continue;
			}
			$row = MsStore::entity($db, $pid, $e['entity_type'], $e['entity_id']);
			if (!MsStore::isLive($row)) {
				continue; // tombstoned by a parent's cascade above
			}
			$one(array('op' => 'delete', 'type' => $e['entity_type'], 'id' => $e['entity_id'], 'baseVersion' => $row['version']));
		}

		// 3. Child order.
		foreach ($changes as $c) {
			if (empty($c['childOrder'])) {
				continue;
			}
			$row = MsStore::entity($db, $pid, $c['type'], $c['id']);
			if (!MsStore::isLive($row)) {
				continue;
			}
			$stored = array();
			foreach (MsModel::$CHILD_KEYS[$c['type']] as $k => $ct) {
				$stored[$k] = isset($row['child_order'][$k]) ? $row['child_order'][$k] : array();
			}
			if (json_encode($stored) !== json_encode($c['childOrder'])) {
				$one(array('op' => 'update', 'type' => $c['type'], 'id' => $c['id'], 'childOrder' => $c['childOrder']));
			}
		}
		$report['stats']['changes'] = $counts;
		return $results;
	}

	/**
	 * Top-level field updates turning $old into $new (dotted-path API: a
	 * value replaces the whole field, null removes it). A null in the upload
	 * and an absent key mean the same (the API cannot store an explicit
	 * null through an update), so the round trip in replace mode compares
	 * with null-valued keys dropped (sameLoose).
	 */
	private static function fieldDiff($type, $old, $new) {
		$skip = array_merge(array('id'), array_keys(MsModel::$CHILD_KEYS[$type]), MsModel::perUserFields($type),
			isset(MsModel::$FIXED_FIELDS[$type]) ? MsModel::$FIXED_FIELDS[$type] : array());
		$o = is_object($old) ? get_object_vars($old) : array();
		$n = is_object($new) ? get_object_vars($new) : array();
		$fields = new stdClass();
		$any = false;
		foreach (array_unique(array_merge(array_keys($o), array_keys($n))) as $k) {
			if (in_array($k, $skip, true) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$k)) {
				continue;
			}
			$ov = array_key_exists($k, $o) ? $o[$k] : null;
			$nv = array_key_exists($k, $n) ? $n[$k] : null;
			if ($ov === null && $nv === null) {
				continue;
			}
			if (!self::same($ov, $nv)) {
				$fields->$k = $nv;
				$any = true;
			}
		}
		return $any ? $fields : null;
	}

	/** same() with null-valued object keys dropped on both sides (see fieldDiff). */
	public static function sameLoose($a, $b) {
		return self::same(self::dropNulls($a), self::dropNulls($b));
	}

	private static function dropNulls($v) {
		if (is_object($v)) {
			$out = new stdClass();
			foreach (get_object_vars($v) as $k => $x) {
				if ($x !== null) {
					$out->$k = self::dropNulls($x);
				}
			}
			return $out;
		}
		if (is_array($v)) {
			return array_map(array('MsConvert', 'dropNulls'), $v);
		}
		return $v;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Entity creates for a normalized project, parents first, nested
	 * micrographs after their parent (same order as a client push).
	 */
	public static function decompose($j) {
		$out = array();
		$emit = function ($type, $obj, $ptype, $pidv) use (&$out) {
			$body = clone $obj;
			$order = array();
			foreach (MsModel::$CHILD_KEYS[$type] as $key => $childType) {
				$order[$key] = array_map(function ($c) { return $c->id; }, $obj->$key);
				unset($body->$key);
			}
			$c = array('op' => 'create', 'type' => $type, 'id' => $obj->id, 'body' => $body);
			if ($ptype !== null) {
				$c['parentType'] = $ptype;
				$c['parentId'] = $pidv;
			}
			if ($order) {
				$c['childOrder'] = $order;
			}
			$out[] = $c;
		};
		$emit('project', $j, null, null);
		foreach (array('tags' => 'tag', 'groups' => 'group', 'presets' => 'preset') as $key => $type) {
			foreach ($j->$key as $x) {
				$emit($type, $x, 'project', $j->id);
			}
		}
		foreach ($j->datasets as $d) {
			$emit('dataset', $d, 'project', $j->id);
			foreach ($d->samples as $s) {
				$emit('sample', $s, 'dataset', $d->id);
				$pending = $s->micrographs;
				$done = array();
				for ($guard = 0; $pending && $guard < 10000; $guard++) {
					$next = array();
					foreach ($pending as $m) {
						$nest = isset($m->parentID) && is_string($m->parentID) && $m->parentID !== '' ? $m->parentID : null;
						if ($nest === null || isset($done[$nest]) || !self::inList($s->micrographs, $nest)) {
							$emit('micrograph', $m, 'sample', $s->id);
							$done[$m->id] = true;
							foreach ($m->spots as $p) {
								$emit('spot', $p, 'micrograph', $m->id);
							}
						} else {
							$next[] = $m;
						}
					}
					if (count($next) === count($pending)) {
						// A loop: push anyway, the rules reject it.
						foreach ($next as $m) {
							$emit('micrograph', $m, 'sample', $s->id);
							foreach ($m->spots as $p) {
								$emit('spot', $p, 'micrograph', $m->id);
							}
						}
						break;
					}
					$pending = $next;
				}
			}
		}
		return $out;
	}

	private static function inList($list, $id) {
		foreach ($list as $x) {
			if ($x->id === $id) {
				return true;
			}
		}
		return false;
	}

	/** Copy of a project with the spine-written sample fields removed. */
	private static function withoutSpine($j) {
		if (!is_object($j) || !isset($j->datasets) || !is_array($j->datasets)) {
			return $j;
		}
		$j = json_decode(json_encode($j));
		foreach ($j->datasets as $d) {
			foreach ((isset($d->samples) && is_array($d->samples)) ? $d->samples : array() as $s) {
				foreach (self::$SPINE_FIELDS as $f) {
					if (is_object($s)) {
						unset($s->$f);
					}
				}
			}
		}
		return $j;
	}

	/** Canonical form (sorted object keys) for comparing JSON documents. */
	public static function canon($v) {
		if (is_object($v)) {
			$v = get_object_vars($v);
			ksort($v, SORT_STRING);
			foreach ($v as $k => $x) {
				$v[$k] = self::canon($x);
			}
			return (object)$v;
		}
		if (is_array($v)) {
			if ($v !== array() && array_keys($v) !== range(0, count($v) - 1)) {
				ksort($v, SORT_STRING);
			}
			foreach ($v as $k => $x) {
				$v[$k] = self::canon($x);
			}
		}
		return $v;
	}

	public static function same($a, $b) {
		return json_encode(self::canon($a), MsHttp::JSON_OUT) === json_encode(self::canon($b), MsHttp::JSON_OUT);
	}

	/** Dotted path of the first difference between two decoded (assoc) documents. */
	private static function firstDifference($a, $b, $path = '') {
		if (is_array($a) && is_array($b)) {
			foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
				if (!array_key_exists($k, $a) || !array_key_exists($k, $b)) {
					return "$path.$k (only in " . (array_key_exists($k, $a) ? 'store' : 'input') . ')';
				}
				$d = self::firstDifference($a[$k], $b[$k], "$path.$k");
				if ($d !== null) {
					return $d;
				}
			}
			return null;
		}
		return json_encode($a) === json_encode($b) ? null : "$path: " . substr(json_encode($a), 0, 80) . ' vs ' . substr(json_encode($b), 0, 80);
	}
}
