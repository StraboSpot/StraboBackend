<?php
/**
 * File: MsWorker.php
 * Description: Derived-view worker for synced StraboMicro projects (design
 *              §4.6): assembles project.json from the live entities, rebuilds
 *              the legacy relational rows in place (same pipeline as a legacy
 *              upload, keeping the project row), search slice and samples
 *              spine, and lays out the files the web viewers read:
 *                images/<micrographId>             hard link to the image blob
 *                associatedFiles/<name>            hard link to the attachment blob
 *                compositeThumbnails/<micrographId> hard link to the thumbnail blob
 *                tiles/<micrographId>/             unpacked from the tiles archive blob
 *                tilesAffine/<micrographId>/       unpacked from the tiles_affine archive blob
 *                point-counts/<sessionId>.json     point_count entity bodies
 *                project.json (+ viewer copies)    via micro_regenerate_files_if_dirty,
 *                                                  which also applies the StraboSamples overlay
 *              No project.zip: downloads of synced projects come from the
 *              streaming .smz endpoint (rollout step 3).
 *
 *              Tile archives (built by the client) are ZIP files holding one
 *              micrograph's pyramid with the same layout as the .smz
 *              tiles/<id>/ folder: metadata.json, thumbnail.jpg, medium.jpg,
 *              tiles/tile_<x>_<y>.webp. Unpacked once per blob hash.
 *
 *              Run by microsync/worker.php (kicked after pushes, and a
 *              per-minute cron sweep). Never inside a transaction:
 *              loadProjectJSON's StraboSamples calls run their own.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsWorker {

	/** Seconds without a new change before a project is rebuilt. */
	public static function quietSeconds() {
		return defined('MICROSYNC_QUIET_SECONDS') ? (int)MICROSYNC_QUIET_SECONDS : 45;
	}

	public static function webRoot() {
		return dirname(__DIR__, 2);
	}

	/** Gitignored data folder (logs), never served (root .htaccess + deny file). */
	public static function dataDir() {
		$d = self::webRoot() . '/microsync_data';
		if (!is_dir("$d/log")) {
			@mkdir("$d/log", 0775, true);
		}
		if (is_dir($d) && !is_file("$d/.htaccess")) {
			@file_put_contents("$d/.htaccess", "# Never served by Apache: microsync worker data\nRequire all denied\n");
		}
		return $d;
	}

	public static function log($msg) {
		@file_put_contents(self::dataDir() . '/log/worker.log',
			gmdate('Y-m-d\TH:i:s\Z') . ' [' . getmypid() . "] $msg\n", FILE_APPEND);
	}

	/**
	 * Start a worker for one project without blocking the request
	 * (ExportBuilder D8 pattern). The worker itself waits for the quiet
	 * period, and only one waits per project, so kicking often is cheap.
	 */
	public static function kick($pid) {
		if (!function_exists('exec')) {
			return false;
		}
		$php = defined('MICROSYNC_PHP_BINARY') ? MICROSYNC_PHP_BINARY : 'php';
		$cmd = 'cd ' . escapeshellarg(self::webRoot()) . ' && nohup ' . escapeshellcmd($php)
			. ' microsync/worker.php --project=' . (int)$pid
			. ' >> ' . escapeshellarg(self::dataDir() . '/log/worker.log') . ' 2>&1 &';
		@exec($cmd);
		return true;
	}

	// ------------------------------------------------------------------
	// State and locks
	// ------------------------------------------------------------------

	/** Dirty/ready state of one project and seconds since its last change. */
	public static function state($db, $pid) {
		return $db->row(
			"SELECT p.sync_format, p.sync_state, p.views_dirty_since IS NOT NULL AS dirty,
			        EXTRACT(EPOCH FROM now() - COALESCE(
			          (SELECT c.at FROM strabomicro.micro_changes c WHERE c.project_id = p.id ORDER BY c.seq DESC LIMIT 1),
			          p.views_dirty_since, now())) AS idle
			   FROM strabomicro.micro_projectmetadata p WHERE p.id = $1 AND NOT " . MsDelete::deletedSql('p'),
			array($pid));
	}

	/** Session-level try-lock; $kind 'wait' (one waiting worker) or 'build'. */
	public static function tryLock($db, $kind, $pid) {
		return $db->val("SELECT pg_try_advisory_lock(hashtext($1), $2)", array('microsync-' . $kind, $pid)) === 't';
	}

	public static function unlock($db, $kind, $pid) {
		$db->q("SELECT pg_advisory_unlock(hashtext($1), $2)", array('microsync-' . $kind, $pid));
	}

	// ------------------------------------------------------------------
	// Assembly
	// ------------------------------------------------------------------

	/**
	 * Read one consistent picture of the project. Returns array with
	 * headSeq, json (project.json text), pointCounts (id => body),
	 * micrographs (ids), refs (role => [entityKey => sha]) or null when
	 * the project entity is missing.
	 */
	public static function assemble($db, $pid, $straboId) {
		// Inside the caller's transaction (the conversion checks its own
		// uncommitted writes) read there; otherwise take a snapshot.
		$own = !$db->inTransaction();
		if ($own) {
			$db->beginSnapshot();
		}
		$head = (int)$db->val("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
		$rows = $db->rows(
			"SELECT entity_type, entity_id, parent_type, parent_id, body::text AS body, child_order::text AS child_order
			   FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND deleted_at IS NULL
			  ORDER BY created_at, entity_id",
			array($pid));
		$refRows = $db->rows(
			"SELECT r.entity_type, r.entity_id, r.role, r.sha256
			   FROM strabomicro.micro_blob_refs r
			   JOIN strabomicro.micro_entities e
			     ON e.project_id = r.project_id AND e.entity_type = r.entity_type AND e.entity_id = r.entity_id
			  WHERE r.project_id = $1 AND e.deleted_at IS NULL",
			array($pid));
		if ($own) {
			$db->commit();
		}

		$bodies = array();
		$orders = array();
		$children = array();
		foreach ($rows as $r) {
			$k = MsModel::key($r['entity_type'], $r['entity_id']);
			$bodies[$k] = json_decode($r['body']);
			$orders[$k] = $r['child_order'] === null ? array() : json_decode($r['child_order'], true);
			if ($r['parent_type'] !== null) {
				$children[MsModel::key($r['parent_type'], $r['parent_id'])][$r['entity_type']][] = $r['entity_id'];
			}
		}
		$rootKey = MsModel::key('project', $straboId);
		if (!isset($bodies[$rootKey])) {
			return null;
		}

		$micrographs = array();
		$pointCounts = array();
		$build = function ($type, $id) use (&$build, $bodies, $orders, $children, &$micrographs, &$pointCounts) {
			$k = MsModel::key($type, $id);
			$obj = $bodies[$k];
			$order = MsModel::normalizeChildOrder($type, $orders[$k], isset($children[$k]) ? $children[$k] : array());
			foreach (MsModel::$CHILD_KEYS[$type] as $childKey => $childType) {
				$list = array();
				foreach ($order[$childKey] as $cid) {
					$list[] = $build($childType, $cid);
				}
				$obj->$childKey = $list;
			}
			if ($type === 'micrograph') {
				$micrographs[] = $id;
				if (isset($children[$k]['point_count'])) {
					foreach ($children[$k]['point_count'] as $pcId) {
						$pointCounts[$pcId] = $bodies[MsModel::key('point_count', $pcId)];
					}
				}
			}
			return $obj;
		};
		$project = $build('project', $straboId);

		$refs = array();
		foreach ($refRows as $r) {
			$refs[$r['role']][MsModel::key($r['entity_type'], $r['entity_id'])] = $r['sha256'];
		}
		return array(
			'headSeq'     => $head,
			'json'        => json_encode($project, MsHttp::JSON_OUT | JSON_PRETTY_PRINT),
			'pointCounts' => $pointCounts,
			'micrographs' => $micrographs,
			'refs'        => $refs,
		);
	}

	// ------------------------------------------------------------------
	// Build
	// ------------------------------------------------------------------

	/**
	 * Rebuild one project's derived views if it is a ready synced project.
	 * Caller holds the build lock. Returns 'built', 'skipped' or an error text.
	 *
	 * $keepPdf: the folder's project.pdf came with an app upload (conversion,
	 * P0-9) and matches this state, so it is not marked for regeneration.
	 * The server's own PDF (MicroProjectPDF) has no micrograph images for
	 * new-app projects, so regenerating would replace the app's PDF with a
	 * text-only one.
	 */
	public static function build($strabodb, $db, $pid, $keepPdf = false) {
		$p = $db->row(
			"SELECT p.id, p.strabo_id, p.userpkey, p.sharekey, p.sync_format, p.sync_state
			   FROM strabomicro.micro_projectmetadata p
			  WHERE p.id = $1 AND NOT " . MsDelete::deletedSql('p'),
			array($pid));
		if ($p === null || $p['sync_format'] !== 'entity' || $p['sync_state'] !== 'ready') {
			return 'skipped';
		}
		$owner = (int)$p['userpkey'];
		$straboId = $p['strabo_id'];
		$started = microtime(true);

		$a = self::assemble($db, $pid, $straboId);
		if ($a === null) {
			return 'no project entity';
		}

		// Relational rows, spine, search: the legacy upload pipeline with the
		// row kept (deleteProjectRows + loadProjectJSON in update mode).
		$sm = new StraboMicro(null, $owner, $strabodb);
		$sm->deleteProjectRows($straboId, true);
		$result = $sm->loadProjectJSON($a['json'], $pid, $p['sharekey'], true);
		if (is_object($result) && isset($result->Error) && $result->Error != '') {
			return 'loadProjectJSON: ' . $result->Error;
		}
		require_once self::webRoot() . '/microdb/lib/search_sync.php';
		micro_search_sync_project($strabodb, $pid, $straboId, $owner);

		self::layoutFiles($pid, $a);

		// project.json copies (+ StraboSamples overlay) through the existing
		// hook; the PDF regenerates on its next request.
		$db->q("UPDATE strabomicro.micro_projectmetadata SET files_dirty = true, pdf_dirty = (pdf_dirty OR NOT $2) WHERE id = $1",
			array($pid, $keepPdf ? 'true' : 'false'));
		require_once self::webRoot() . '/microdb/lib/sample_overlay.php';
		micro_regenerate_files_if_dirty($strabodb, $pid, $owner);

		// Clean again only if nothing arrived since the snapshot.
		$db->q(
			"UPDATE strabomicro.micro_projectmetadata
			    SET views_built_at = now(),
			        views_dirty_since = CASE WHEN head_seq = $2 THEN NULL ELSE views_dirty_since END
			  WHERE id = $1",
			array($pid, $a['headSeq']));
		self::log(sprintf('built project %d at seq %d in %.1f s (%d micrographs)',
			$pid, $a['headSeq'], microtime(true) - $started, count($a['micrographs'])));
		return 'built';
	}

	/** Make the project's file tree match the assembled state. */
	private static function layoutFiles($pid, $a) {
		$root = MsStore::filesRoot() . '/' . (int)$pid;
		@mkdir($root, 0775, true);
		$refs = $a['refs'];

		$want = array('images' => array(), 'compositeThumbnails' => array(), 'associatedFiles' => array());
		foreach ($a['micrographs'] as $mid) {
			$k = MsModel::key('micrograph', $mid);
			if (isset($refs['image'][$k])) {
				$want['images'][$mid] = $refs['image'][$k];
			}
			if (isset($refs['thumbnail'][$k])) {
				$want['compositeThumbnails'][$mid] = $refs['thumbnail'][$k];
			}
		}
		foreach ($refs as $role => $byEntity) {
			if (strpos($role, 'associated_file:') === 0) {
				foreach ($byEntity as $sha) {
					$want['associatedFiles'][substr($role, strlen('associated_file:'))] = $sha;
				}
			}
		}
		foreach ($want as $folder => $files) {
			self::syncLinks($pid, "$root/$folder", $files);
		}

		foreach (array('tiles' => 'tiles', 'tilesAffine' => 'tiles_affine') as $folder => $role) {
			$wantTiles = array();
			foreach ($a['micrographs'] as $mid) {
				$k = MsModel::key('micrograph', $mid);
				if (isset($refs[$role][$k])) {
					$wantTiles[$mid] = $refs[$role][$k];
				}
			}
			self::syncTiles($pid, "$root/$folder", $wantTiles);
		}

		$pcDir = "$root/point-counts";
		@mkdir($pcDir, 0775, true);
		$keep = array();
		foreach ($a['pointCounts'] as $id => $body) {
			$name = self::safeName($id) . '.json';
			$keep[$name] = true;
			$text = json_encode($body, MsHttp::JSON_OUT | JSON_PRETTY_PRINT);
			if (!is_file("$pcDir/$name") || file_get_contents("$pcDir/$name") !== $text) {
				file_put_contents("$pcDir/$name.tmp", $text);
				rename("$pcDir/$name.tmp", "$pcDir/$name");
			}
		}
		self::removeOthers($pcDir, $keep);
	}

	/** File names come from entity ids and attachment names: keep them inside the folder. */
	private static function safeName($name) {
		$name = str_replace(array('/', '\\', "\0"), '_', (string)$name);
		return ($name === '.' || $name === '..' || $name === '') ? '_' : $name;
	}

	/** $dir/<name> = hard link to blob <sha>; everything else in $dir removed. */
	private static function syncLinks($pid, $dir, $files) {
		@mkdir($dir, 0775, true);
		$keep = array();
		foreach ($files as $name => $sha) {
			$name = self::safeName($name);
			$keep[$name] = true;
			$blob = MsStore::blobPath($pid, $sha);
			$target = "$dir/$name";
			if (!is_file($blob)) {
				continue;
			}
			clearstatcache(true, $target);
			if (is_file($target) && fileinode($target) === fileinode($blob)) {
				continue;
			}
			$tmp = "$target.ms-" . getmypid();
			@unlink($tmp);
			if (!@link($blob, $tmp) && !@copy($blob, $tmp)) {
				self::log("project $pid: could not place $target");
				continue;
			}
			rename($tmp, $target);
		}
		self::removeOthers($dir, $keep);
	}

	/** $dir/<micrographId>/ = unpacked tiles archive; marker .blob holds its sha. */
	private static function syncTiles($pid, $dir, $wantTiles) {
		@mkdir($dir, 0775, true);
		$keep = array();
		foreach ($wantTiles as $mid => $sha) {
			$name = self::safeName($mid);
			$keep[$name] = true;
			$target = "$dir/$name";
			if (is_file("$target/.blob") && trim(file_get_contents("$target/.blob")) === $sha) {
				continue;
			}
			$blob = MsStore::blobPath($pid, $sha);
			if (!is_file($blob)) {
				continue;
			}
			$tmp = "$dir/.ms-$name-" . getmypid();
			self::rmTree($tmp);
			if (!self::unzipSafely($blob, $tmp)) {
				self::rmTree($tmp);
				self::log("project $pid: tiles archive $sha for $mid is not a valid archive");
				continue;
			}
			file_put_contents("$tmp/.blob", $sha);
			$old = "$dir/.ms-old-$name-" . getmypid();
			if (is_dir($target)) {
				rename($target, $old);
			}
			rename($tmp, $target);
			self::rmTree($old);
		}
		self::removeOthers($dir, $keep);
	}

	/** Extract a ZIP, refusing absolute paths and "..". */
	private static function unzipSafely($zipPath, $dest) {
		if (!class_exists('ZipArchive')) {
			return false;
		}
		$za = new ZipArchive();
		if ($za->open($zipPath) !== true) {
			return false;
		}
		for ($i = 0; $i < $za->numFiles; $i++) {
			$n = $za->getNameIndex($i);
			if ($n === false || $n === '' || $n[0] === '/' || strpos($n, '\\') !== false
				|| preg_match('#(^|/)\.\.(/|$)#', $n)) {
				$za->close();
				return false;
			}
		}
		@mkdir($dest, 0775, true);
		$ok = $za->extractTo($dest);
		$za->close();
		return $ok;
	}

	private static function removeOthers($dir, $keep) {
		foreach ((array)@scandir($dir) as $f) {
			if ($f === '.' || $f === '..' || isset($keep[$f])) {
				continue;
			}
			self::rmTree("$dir/$f");
		}
	}

	private static function rmTree($path) {
		if (is_link($path) || is_file($path)) {
			@unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		foreach ((array)@scandir($path) as $f) {
			if ($f !== '.' && $f !== '..') {
				self::rmTree("$path/$f");
			}
		}
		@rmdir($path);
	}

	// ------------------------------------------------------------------
	// Housekeeping (cron sweep)
	// ------------------------------------------------------------------

	public static function housekeeping($db) {
		$old = $db->rows(
			"DELETE FROM strabomicro.micro_uploads WHERE updated_at < now() - interval '7 days' RETURNING upload_id",
			array());
		foreach ($old as $r) {
			@unlink(MsStore::stagingPath($r['upload_id']));
		}
		// Staging files with no upload row (crashed before insert), older than a week.
		$staging = MsStore::filesRoot() . '/_staging';
		$live = array();
		foreach ($db->rows("SELECT upload_id FROM strabomicro.micro_uploads", array()) as $r) {
			$live[$r['upload_id']] = true;
		}
		foreach ((array)@scandir($staging) as $f) {
			if ($f !== '.' && $f !== '..' && !isset($live[$f]) && @filemtime("$staging/$f") < time() - 7 * 86400) {
				@unlink("$staging/$f");
			}
		}
		$db->q("DELETE FROM strabomicro.micro_presence WHERE last_seen < now() - interval '1 day'", array());
		return count($old);
	}
}
