<?php
/**
 * File: MsBlobs.php
 * Description: Content-addressed blobs for the /microsync/v1/ API (design
 *              §4.3, P0-8, P0-13, P0-16): presence check, download with
 *              Range, chunked resumable upload, and blob refs (which blob is
 *              a micrograph's image, tiles, thumbnail, or a named attachment).
 *
 *              Files: straboMicroFiles/<pid>/blobs/<sha256>; staging in
 *              straboMicroFiles/_staging/<uploadId>. Chunks are sequential and
 *              exactly chunkSize bytes except the last.
 *
 *              Ref changes go through the change log as op "update" with
 *              changedPaths ["refs.<role>"] and an unchanged version (like
 *              childOrder), so pulls learn about new images and attachments.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsBlobs {

	const CHUNK_SIZE = 16777216;       // 16 MB
	const MAX_SIZE = 107374182400;     // 100 GB

	public static $KINDS = array('image', 'tiles', 'tiles_affine', 'thumbnail', 'associated_file');

	/** Ref roles on derived viewer assets any writer may set (P0-4). */
	public static $DERIVED_ROLES = array('tiles', 'tiles_affine', 'thumbnail');

	// ------------------------------------------------------------------
	// HEAD / GET projects/{pid}/blobs/{sha256}
	// ------------------------------------------------------------------

	private static function blobRow($db, $pid, $sha) {
		$row = $db->row(
			"SELECT sha256, size, kind FROM strabomicro.micro_blobs WHERE project_id = $1 AND sha256 = $2",
			array($pid, $sha));
		if ($row === null || !is_file(MsStore::blobPath($pid, $sha))) {
			return null;
		}
		return $row;
	}

	public static function head($ctx, $pid, $sha) {
		MsStore::project($ctx->db, $pid, $ctx->me);
		$row = self::blobRow($ctx->db, $pid, $sha);
		if ($row === null) {
			http_response_code(404);
			return;
		}
		http_response_code(200);
		header('Content-Type: application/octet-stream');
		header('Content-Length: ' . $row['size']);
		header('X-Blob-Kind: ' . $row['kind']);
	}

	public static function get($ctx, $pid, $sha) {
		MsStore::project($ctx->db, $pid, $ctx->me);
		$row = self::blobRow($ctx->db, $pid, $sha);
		if ($row === null) {
			throw new MsHttpError(404, 'not_found', 'Blob not found');
		}
		$size = (int)$row['size'];
		$start = 0;
		$end = $size - 1;
		$status = 200;
		if (isset($_SERVER['HTTP_RANGE']) && $_SERVER['HTTP_RANGE'] !== '') {
			if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) || ($m[1] === '' && $m[2] === '')) {
				throw new MsHttpError(416, 'bad_range', 'Only a single byte range is supported');
			}
			if ($m[1] === '') {
				$start = max(0, $size - (int)$m[2]);
			} else {
				$start = (int)$m[1];
				if ($m[2] !== '') {
					$end = min($end, (int)$m[2]);
				}
			}
			if ($start > $end || $start >= $size) {
				header("Content-Range: bytes */$size");
				throw new MsHttpError(416, 'bad_range', 'Range not satisfiable');
			}
			$status = 206;
		}

		set_time_limit(0);
		$fh = fopen(MsStore::blobPath($pid, $sha), 'rb');
		if ($fh === false) {
			throw new MsHttpError(500, 'server_error', 'Blob could not be opened');
		}
		http_response_code($status);
		header('Content-Type: application/octet-stream');
		header('Accept-Ranges: bytes');
		header('ETag: "' . $sha . '"');
		header('Cache-Control: private, max-age=31536000, immutable');
		header('Content-Length: ' . ($end - $start + 1));
		if ($status === 206) {
			header("Content-Range: bytes $start-$end/$size");
		}
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		fseek($fh, $start);
		$left = $end - $start + 1;
		while ($left > 0 && !feof($fh)) {
			$buf = fread($fh, min(1048576, $left));
			if ($buf === false || $buf === '') {
				break;
			}
			echo $buf;
			flush();
			$left -= strlen($buf);
			if (connection_aborted()) {
				break;
			}
		}
		fclose($fh);
	}

	// ------------------------------------------------------------------
	// Uploads
	// ------------------------------------------------------------------

	/** POST projects/{pid}/uploads {sha256, size, kind} */
	public static function startUpload($ctx, $pid) {
		$db = $ctx->db;
		$in = MsHttp::readJson(65536);
		$sha = MsHttp::prop($in, 'sha256');
		$size = MsHttp::prop($in, 'size');
		$kind = MsHttp::prop($in, 'kind');
		if (!MsHttp::isSha256($sha)) {
			throw new MsHttpError(400, 'bad_request', 'sha256 must be 64 lowercase hex characters');
		}
		if (!is_int($size) || $size < 1 || $size > self::MAX_SIZE) {
			throw new MsHttpError(400, 'bad_request', 'size must be a positive integer');
		}
		if (!in_array($kind, self::$KINDS, true)) {
			throw new MsHttpError(400, 'bad_request', 'kind must be one of ' . implode(', ', self::$KINDS));
		}
		$p = MsStore::project($db, $pid, $ctx->me);
		if (!MsStore::canWrite($p['role'])) {
			throw new MsHttpError(403, 'forbidden', 'Viewers cannot upload');
		}

		$present = self::blobRow($db, $pid, $sha);
		if ($present !== null) {
			MsHttp::json(200, array('complete' => true, 'sha256' => $sha, 'size' => (int)$present['size'], 'kind' => $present['kind']));
			return;
		}

		$db->begin();
		$up = $db->row(
			"SELECT upload_id, size, kind, chunk_size, received FROM strabomicro.micro_uploads
			  WHERE project_id = $1 AND user_pkey = $2 AND sha256 = $3 FOR UPDATE",
			array($pid, $ctx->me, $sha));
		if ($up !== null) {
			if ((int)$up['size'] !== $size || $up['kind'] !== $kind) {
				$db->rollback();
				throw new MsHttpError(409, 'upload_mismatch', 'An unfinished upload of this blob has a different size or kind');
			}
			$received = self::reconcileStaging($db, $up);
			$db->commit();
			MsHttp::json(200, array('uploadId' => $up['upload_id'], 'chunkSize' => (int)$up['chunk_size'],
				'received' => $received, 'size' => $size));
			return;
		}
		$uploadId = MsHttp::uuid4();
		$path = MsStore::stagingPath($uploadId);
		if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
			$db->rollback();
			throw new MsHttpError(500, 'server_error', 'Staging folder could not be created');
		}
		if (@file_put_contents($path, '') === false) {
			$db->rollback();
			throw new MsHttpError(500, 'server_error', 'Staging file could not be created');
		}
		$db->q(
			"INSERT INTO strabomicro.micro_uploads (upload_id, project_id, user_pkey, sha256, size, kind, chunk_size)
			 VALUES ($1, $2, $3, $4, $5, $6, $7)",
			array($uploadId, $pid, $ctx->me, $sha, $size, $kind, self::CHUNK_SIZE));
		$db->commit();
		MsHttp::json(201, array('uploadId' => $uploadId, 'chunkSize' => self::CHUNK_SIZE, 'received' => 0, 'size' => $size));
	}

	/**
	 * Make received agree with the staging file after a crash: a shorter
	 * file rewinds to its last whole chunk, a longer one is truncated.
	 */
	private static function reconcileStaging($db, $up) {
		$path = MsStore::stagingPath($up['upload_id']);
		$received = (int)$up['received'];
		clearstatcache(true, $path);
		$onDisk = is_file($path) ? filesize($path) : -1;
		if ($onDisk === $received) {
			return $received;
		}
		if ($onDisk < 0) {
			@file_put_contents($path, '');
			$received = 0;
		} elseif ($onDisk < $received) {
			$received = (int)(floor($onDisk / (int)$up['chunk_size']) * (int)$up['chunk_size']);
			$fh = fopen($path, 'r+b');
			ftruncate($fh, $received);
			fclose($fh);
		} else {
			$fh = fopen($path, 'r+b');
			ftruncate($fh, $received);
			fclose($fh);
		}
		$db->q("UPDATE strabomicro.micro_uploads SET received = $2, updated_at = now() WHERE upload_id = $1",
			array($up['upload_id'], $received));
		return $received;
	}

	private static function lockUpload($db, $pid, $me, $uploadId) {
		$up = $db->row(
			"SELECT upload_id, sha256, size, kind, chunk_size, received FROM strabomicro.micro_uploads
			  WHERE upload_id = $1 AND project_id = $2 AND user_pkey = $3 FOR UPDATE",
			array($uploadId, $pid, $me));
		if ($up === null) {
			throw new MsHttpError(404, 'not_found', 'Upload not found');
		}
		return $up;
	}

	/** PUT projects/{pid}/uploads/{uploadId}?offset=n  (raw chunk body) */
	public static function putChunk($ctx, $pid, $uploadId) {
		$db = $ctx->db;
		if (!isset($_GET['offset'])) {
			throw new MsHttpError(400, 'bad_request', 'offset is required');
		}
		$offset = MsHttp::queryInt('offset', 0, 0, PHP_INT_MAX);
		MsStore::project($db, $pid, $ctx->me);

		$db->begin();
		$up = self::lockUpload($db, $pid, $ctx->me, strtolower($uploadId));
		$received = self::reconcileStaging($db, $up);
		$size = (int)$up['size'];
		if ($offset !== $received) {
			$db->commit();
			throw new MsHttpError(409, 'offset_mismatch', "Expected offset $received", array('received' => $received));
		}
		if ($received >= $size) {
			$db->commit();
			throw new MsHttpError(409, 'already_received', 'All bytes were received; call complete', array('received' => $received));
		}
		$expected = min((int)$up['chunk_size'], $size - $received);
		$declared = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : -1;
		if ($declared !== -1 && $declared !== $expected) {
			$db->commit();
			throw new MsHttpError(400, 'bad_chunk', "This chunk must be exactly $expected bytes",
				array('expectedLength' => $expected));
		}

		set_time_limit(0);
		$path = MsStore::stagingPath($up['upload_id']);
		$in = fopen('php://input', 'rb');
		$fh = fopen($path, 'r+b');
		if ($in === false || $fh === false) {
			$db->rollback();
			throw new MsHttpError(500, 'server_error', 'Staging file could not be opened');
		}
		fseek($fh, $received);
		$written = stream_copy_to_stream($in, $fh, $expected + 1);
		fflush($fh);
		if ($written !== $expected) {
			ftruncate($fh, $received);
			fclose($fh);
			fclose($in);
			$db->commit();
			throw new MsHttpError(400, 'bad_chunk', "This chunk must be exactly $expected bytes",
				array('expectedLength' => $expected, 'received' => $received));
		}
		fclose($fh);
		fclose($in);
		$received += $written;
		$db->q("UPDATE strabomicro.micro_uploads SET received = $2, updated_at = now() WHERE upload_id = $1",
			array($up['upload_id'], $received));
		$db->commit();
		MsHttp::json(200, array('received' => $received, 'size' => $size));
	}

	/** POST projects/{pid}/uploads/{uploadId}/complete */
	public static function complete($ctx, $pid, $uploadId) {
		$db = $ctx->db;
		MsStore::project($db, $pid, $ctx->me);
		$db->begin();
		$up = self::lockUpload($db, $pid, $ctx->me, strtolower($uploadId));
		$received = self::reconcileStaging($db, $up);
		$size = (int)$up['size'];
		if ($received !== $size) {
			$db->commit();
			throw new MsHttpError(409, 'incomplete', "Received $received of $size bytes", array('received' => $received));
		}
		set_time_limit(0);
		$staging = MsStore::stagingPath($up['upload_id']);
		$actual = hash_file('sha256', $staging);
		if ($actual !== $up['sha256']) {
			@unlink($staging);
			$db->q("DELETE FROM strabomicro.micro_uploads WHERE upload_id = $1", array($up['upload_id']));
			$db->commit();
			throw new MsHttpError(422, 'hash_mismatch', 'The uploaded bytes do not match sha256; start the upload again');
		}
		$dest = MsStore::blobPath($pid, $up['sha256']);
		if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0775, true) && !is_dir(dirname($dest))) {
			$db->rollback();
			throw new MsHttpError(500, 'server_error', 'Blob folder could not be created');
		}
		if (is_file($dest)) {
			@unlink($staging);
		} elseif (!@rename($staging, $dest)) {
			if (!@copy($staging, $dest)) {
				$db->rollback();
				throw new MsHttpError(500, 'server_error', 'Blob could not be stored');
			}
			@unlink($staging);
		}
		$db->q(
			"INSERT INTO strabomicro.micro_blobs (project_id, sha256, size, kind, uploaded_by)
			 VALUES ($1, $2, $3, $4, $5) ON CONFLICT (project_id, sha256) DO NOTHING",
			array($pid, $up['sha256'], $size, $up['kind'], $ctx->me));
		$db->q("DELETE FROM strabomicro.micro_uploads WHERE upload_id = $1", array($up['upload_id']));
		$db->commit();
		MsHttp::json(200, array('complete' => true, 'sha256' => $up['sha256'], 'size' => $size, 'kind' => $up['kind']));
	}

	// ------------------------------------------------------------------
	// Refs: PUT projects/{pid}/refs  {entityType, entityId, role, sha256}
	//       DELETE projects/{pid}/refs?entityType=&entityId=&role=
	// ------------------------------------------------------------------

	/** Blob kind a ref role needs, or null for a bad role. */
	public static function roleKind($role) {
		if (in_array($role, array('image', 'tiles', 'tiles_affine', 'thumbnail'), true)) {
			return $role;
		}
		if (is_string($role) && strpos($role, 'associated_file:') === 0) {
			$name = substr($role, strlen('associated_file:'));
			if ($name !== '' && strlen($name) <= 255 && !preg_match('#[/\\\\\x00-\x1f]#', $name) && $name !== '.' && $name !== '..') {
				return 'associated_file';
			}
		}
		return null;
	}

	public static function putRef($ctx, $pid) {
		$in = MsHttp::readJson(65536);
		self::changeRef($ctx, $pid, MsHttp::prop($in, 'entityType'), MsHttp::prop($in, 'entityId'),
			MsHttp::prop($in, 'role'), MsHttp::prop($in, 'sha256'));
	}

	public static function deleteRef($ctx, $pid) {
		self::changeRef($ctx, $pid,
			isset($_GET['entityType']) ? (string)$_GET['entityType'] : null,
			isset($_GET['entityId']) ? (string)$_GET['entityId'] : null,
			isset($_GET['role']) ? (string)$_GET['role'] : null,
			null);
	}

	/** Set ($sha given) or remove ($sha null) one ref, logged as a change. */
	private static function changeRef($ctx, $pid, $type, $id, $role, $sha) {
		$db = $ctx->db;
		if (!MsModel::isType($type) || !MsModel::isId($id)) {
			throw new MsHttpError(400, 'bad_request', 'entityType and entityId are required');
		}
		$kind = self::roleKind($role);
		if ($kind === null) {
			throw new MsHttpError(400, 'bad_request', 'role must be image, tiles, tiles_affine, thumbnail, or associated_file:<name>');
		}
		if ($sha !== null && !MsHttp::isSha256($sha)) {
			throw new MsHttpError(400, 'bad_request', 'sha256 must be 64 lowercase hex characters');
		}

		MsStore::project($db, $pid, $ctx->me);
		$db->begin();
		MsStore::lockProject($db, $pid);
		$p = MsStore::project($db, $pid, $ctx->me);
		if (!MsStore::canWrite($p['role'])) {
			throw new MsHttpError(403, 'forbidden', 'Viewers cannot change files');
		}
		$row = MsStore::entity($db, $pid, $type, $id);
		if (!MsStore::isLive($row)) {
			throw new MsHttpError(404, 'not_found', "Live $type $id not found");
		}
		if (!in_array($role, self::$DERIVED_ROLES, true)
			&& $p['role'] === 'contributor' && $row['created_by'] !== $ctx->me) {
			throw new MsHttpError(403, 'forbidden', 'Contributors can change files only on entities they created',
				array('reason' => 'contributor_not_creator'));
		}

		$current = $db->val(
			"SELECT sha256 FROM strabomicro.micro_blob_refs
			  WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3 AND role = $4",
			array($pid, $type, $id, $role));
		if ($current === $sha) {
			$db->commit();
			MsHttp::json(200, array('changed' => false, 'headSeq' => $p['head_seq']));
			return;
		}

		if ($sha !== null) {
			$blob = $db->row("SELECT kind FROM strabomicro.micro_blobs WHERE project_id = $1 AND sha256 = $2",
				array($pid, $sha));
			if ($blob === null) {
				throw new MsHttpError(409, 'blob_missing', 'Upload the blob before referencing it');
			}
			if ($blob['kind'] !== $kind) {
				throw new MsHttpError(400, 'bad_request', "A $role ref needs a blob of kind $kind, not " . $blob['kind']);
			}
			if ($kind === 'associated_file') {
				// P0-16: an attachment name belongs to one content per project.
				$taken = $db->val(
					"SELECT 1 FROM strabomicro.micro_blob_refs
					  WHERE project_id = $1 AND lower(role) = lower($2) AND sha256 <> $3 LIMIT 1",
					array($pid, $role, $sha));
				if ($taken !== null) {
					throw new MsHttpError(409, 'name_taken', 'Another file with this name is already attached in this project; rename it');
				}
			}
		}

		$before = MsStore::state($db, $pid, $row);
		if ($sha === null) {
			$db->q(
				"DELETE FROM strabomicro.micro_blob_refs
				  WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3 AND role = $4",
				array($pid, $type, $id, $role));
		} else {
			$db->q(
				"INSERT INTO strabomicro.micro_blob_refs (project_id, sha256, entity_type, entity_id, role)
				 VALUES ($1, $2, $3, $4, $5)
				 ON CONFLICT (project_id, entity_type, entity_id, role) DO UPDATE SET sha256 = EXCLUDED.sha256",
				array($pid, $sha, $type, $id, $role));
		}
		$after = MsStore::state($db, $pid, $row);
		$seq = MsStore::logChange($db, $ctx, $pid, $type, $id, 'update', $row['version'],
			array('refs.' . $role), $before, $after);
		MsStore::bumpHead($db, $pid, $seq);
		$db->commit();
		MsWorker::kick($pid);
		MsHttp::json(200, array('changed' => true, 'seq' => $seq, 'headSeq' => $seq));
	}
}
