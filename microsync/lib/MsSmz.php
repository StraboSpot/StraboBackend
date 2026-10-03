<?php
/**
 * File: MsSmz.php
 * Description: Streaming .smz for synced StraboMicro projects (rollout step 3,
 *              design P0-10 / §4.5). Synced projects have no static
 *              project.zip; every legacy download door (getProjectURL,
 *              getSharedURL, share codes, download_micro_file.php, deep
 *              links) gets this archive instead, assembled on the fly from
 *              one consistent snapshot of the entity store:
 *
 *                <straboId>/project.json                assembled + StraboSamples overlay
 *                <straboId>/images/<micrographId>       image blob
 *                <straboId>/compositeThumbnails/<id>    thumbnail blob
 *                <straboId>/associatedFiles/<name>      attachment blobs
 *                <straboId>/tiles/<id>/...              entries of the tiles archive blob
 *                <straboId>/tilesAffine/<id>/...        entries of the tiles_affine archive blob
 *                <straboId>/point-counts/<id>.json      point_count entity bodies
 *
 *              Same layout as the app's own export (electron/smzExport.js),
 *              which every StraboMicro2 release reads with unzipper 0.12.3.
 *              The archive is written in store mode with sizes and CRCs in
 *              every local header (no data descriptors), ZIP64 only when
 *              needed, and its exact length is known before the first byte,
 *              so downloads carry Content-Length (the app's progress bar).
 *              Tile archive entries are copied raw (no unpack, no
 *              recompression), keeping their method and CRC. Blob CRCs are
 *              cached next to the blob (<sha>.crc32; blobs never change).
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
require_once __DIR__ . '/MsWorker.php';

class MsSmz {

	const U32 = 0xFFFFFFFF;

	/** Tests only: force ZIP64 records on every entry and at the end. */
	public static $forceZip64 = false;

	// ------------------------------------------------------------------
	// Door for the legacy endpoints
	// ------------------------------------------------------------------

	/**
	 * The synced project row by internal id when a legacy door must stream
	 * it, else null (legacy project, unknown id, or initial upload not
	 * finished). Takes the $db wrapper.
	 */
	public static function syncedRow($strabodb, $pid) {
		$ms = new MsDb($strabodb);
		$row = $ms->row(
			"SELECT p.id, p.strabo_id, p.name, p.userpkey, p.sync_format, p.sync_state
			   FROM strabomicro.micro_projectmetadata p
			  WHERE p.id = $1 AND NOT " . MsDelete::deletedSql('p'),
			array((int)$pid));
		if ($row === null || $row['sync_format'] !== 'entity' || $row['sync_state'] !== 'ready') {
			return null;
		}
		return $row;
	}

	/** Exact byte length of the archive a download would send now. */
	public static function length($strabodb, $pid) {
		$plan = self::plan($strabodb, $pid, false);
		return $plan === null ? 0 : $plan['length'];
	}

	/**
	 * Stream the archive as the whole HTTP response. Returns false (nothing
	 * sent) when the project cannot be assembled.
	 */
	public static function send($strabodb, $pid, $downloadName) {
		set_time_limit(0);
		$plan = self::plan($strabodb, $pid, true);
		if ($plan === null) {
			return false;
		}
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
		}
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
		header('Content-Length: ' . $plan['length']);
		header('Cache-Control: no-store');
		$sent = self::write($plan, function ($bytes) {
			echo $bytes;
			flush();
			return connection_aborted() === 0;
		});
		if ($sent !== null && $sent !== $plan['length']) {
			MsWorker::log(sprintf('smz project %d: wrote %d of %d bytes', $pid, $sent, $plan['length']));
		}
		return true;
	}

	// ------------------------------------------------------------------
	// Plan
	// ------------------------------------------------------------------

	/**
	 * Entry list of the archive, with offsets and the total length.
	 * $withCrc false skips computing blob CRCs (length only; a CRC never
	 * changes the length). Null when the project entity is missing.
	 *
	 * Entry: name, method, crc (int or null), csize, usize, and a source:
	 *   data => string | path + offset (raw bytes of a file or archive entry).
	 */
	public static function plan($strabodb, $pid, $withCrc) {
		$ms = new MsDb($strabodb);
		$row = $ms->row(
			"SELECT strabo_id, userpkey FROM strabomicro.micro_projectmetadata
			  WHERE id = $1 AND sync_format = 'entity'",
			array((int)$pid));
		if ($row === null) {
			return null;
		}
		$straboId = $row['strabo_id'];
		$a = MsWorker::assemble($ms, $pid, $straboId);
		if ($a === null) {
			return null;
		}
		$root = self::safeName($straboId) . '/';
		$entries = array();

		// project.json: what a legacy download serves (spine overlay applied).
		$json = $a['json'];
		$decoded = json_decode($json);
		if (is_object($decoded)) {
			require_once dirname(__DIR__, 2) . '/microdb/lib/sample_overlay.php';
			micro_sample_overlay_apply($decoded, $strabodb, (int)$row['userpkey']);
			$json = json_encode($decoded, MsHttp::JSON_OUT | JSON_PRETTY_PRINT);
		}
		$entries[] = self::dataEntry($root . 'project.json', $json);

		$refs = $a['refs'];
		$blobEntry = function ($name, $sha) use ($pid, $withCrc) {
			$path = MsStore::blobPath($pid, $sha);
			clearstatcache(true, $path);
			if (!is_file($path)) {
				MsWorker::log("smz project $pid: blob $sha missing, $name left out");
				return null;
			}
			$size = filesize($path);
			return array(
				'name' => $name, 'method' => 0, 'usize' => $size, 'csize' => $size,
				'crc' => $withCrc ? self::blobCrc($path) : null,
				'path' => $path, 'offset' => 0,
			);
		};

		foreach ($a['micrographs'] as $mid) {
			$k = MsModel::key('micrograph', $mid);
			$safe = self::safeName($mid);
			foreach (array('image' => 'images', 'thumbnail' => 'compositeThumbnails') as $role => $folder) {
				if (isset($refs[$role][$k])) {
					$e = $blobEntry($root . "$folder/$safe", $refs[$role][$k]);
					if ($e !== null) {
						$entries[] = $e;
					}
				}
			}
		}

		// Attachments: one file per name (names are unique by content, P0-16).
		$files = array();
		foreach ($refs as $role => $byEntity) {
			if (strpos($role, 'associated_file:') === 0) {
				foreach ($byEntity as $sha) {
					$files[self::safeName(substr($role, strlen('associated_file:')))] = $sha;
				}
			}
		}
		ksort($files, SORT_STRING);
		foreach ($files as $name => $sha) {
			$e = $blobEntry($root . "associatedFiles/$name", $sha);
			if ($e !== null) {
				$entries[] = $e;
			}
		}

		foreach (array('tiles' => 'tiles', 'tiles_affine' => 'tilesAffine') as $role => $folder) {
			foreach ($a['micrographs'] as $mid) {
				$k = MsModel::key('micrograph', $mid);
				if (!isset($refs[$role][$k])) {
					continue;
				}
				$sha = $refs[$role][$k];
				$path = MsStore::blobPath($pid, $sha);
				$inner = is_file($path) ? MsZipReader::entries($path) : null;
				if ($inner === null) {
					MsWorker::log("smz project $pid: $role archive $sha for $mid missing or unreadable, left out");
					continue;
				}
				$prefix = $root . $folder . '/' . self::safeName($mid) . '/';
				foreach ($inner as $ie) {
					$ie['name'] = $prefix . $ie['name'];
					$entries[] = $ie;
				}
			}
		}

		ksort($a['pointCounts'], SORT_STRING);
		foreach ($a['pointCounts'] as $id => $body) {
			$entries[] = self::dataEntry($root . 'point-counts/' . self::safeName($id) . '.json',
				json_encode($body, MsHttp::JSON_OUT | JSON_PRETTY_PRINT));
		}

		// Timestamps from the last change, so one project state always
		// yields the same bytes.
		$at = $ms->val(
			"SELECT floor(extract(epoch FROM max(at))) FROM strabomicro.micro_changes WHERE project_id = $1",
			array((int)$pid));
		return self::layout($entries, $at === null ? time() : (int)$at);
	}

	/**
	 * Write a store-mode ZIP of files (name => path) to $dest with every
	 * timestamp set to $mtime, so the same files always give the same
	 * bytes (the conversion's tile archives). Returns the byte count or null.
	 */
	public static function writeStoreArchive($files, $mtime, $dest) {
		ksort($files, SORT_STRING);
		$entries = array();
		foreach ($files as $name => $path) {
			$size = filesize($path);
			$entries[] = array('name' => $name, 'method' => 0, 'usize' => $size, 'csize' => $size,
				'crc' => (int)hexdec(hash_file('crc32b', $path)), 'path' => $path, 'offset' => 0);
		}
		$fh = fopen($dest, 'wb');
		if ($fh === false) {
			return null;
		}
		$n = self::write(self::layout($entries, $mtime), function ($bytes) use ($fh) {
			return fwrite($fh, $bytes) === strlen($bytes);
		});
		fclose($fh);
		return $n;
	}

	private static function dataEntry($name, $data) {
		$n = strlen($data);
		return array('name' => $name, 'method' => 0, 'usize' => $n, 'csize' => $n,
			'crc' => (int)hexdec(hash('crc32b', $data)), 'data' => $data);
	}

	/** Same rule as the worker: names stay inside their folder. */
	public static function safeName($name) {
		$name = str_replace(array('/', '\\', "\0"), '_', (string)$name);
		return ($name === '.' || $name === '..' || $name === '') ? '_' : $name;
	}

	/** CRC-32 of a blob, cached in <blob>.crc32 (blobs are immutable). */
	public static function blobCrc($path) {
		$cache = $path . '.crc32';
		if (is_file($cache)) {
			$hex = trim((string)@file_get_contents($cache));
			if (preg_match('/^[0-9a-f]{8}$/', $hex)) {
				return (int)hexdec($hex);
			}
		}
		$hex = hash_file('crc32b', $path);
		$tmp = $cache . '.' . getmypid();
		if (@file_put_contents($tmp, $hex) !== false) {
			@rename($tmp, $cache);
		}
		return (int)hexdec($hex);
	}

	// ------------------------------------------------------------------
	// ZIP layout and writing
	// ------------------------------------------------------------------

	/** Assign local header offsets and compute every record's size. */
	private static function layout($entries, $mtime) {
		list($dosTime, $dosDate) = self::dosTime($mtime);
		$offset = 0;
		foreach ($entries as $i => $e) {
			$e['time'] = $dosTime;
			$e['date'] = $dosDate;
			$e['zip64'] = self::$forceZip64 || $e['usize'] >= self::U32 || $e['csize'] >= self::U32;
			$e['offsetHdr'] = $offset;
			$e['local'] = null; // built on write (needs the CRC)
			$localLen = 30 + strlen($e['name']) + ($e['zip64'] ? 20 : 0);
			$offset += $localLen + $e['csize'];
			$entries[$i] = $e;
		}
		$cdStart = $offset;
		$cdLen = 0;
		foreach ($entries as $e) {
			$cdLen += 46 + strlen($e['name']) + self::centralExtraLen($e);
		}
		$zip64End = self::$forceZip64 || count($entries) >= 0xFFFF
			|| $cdStart >= self::U32 || $cdLen >= self::U32;
		$length = $cdStart + $cdLen + ($zip64End ? 56 + 20 : 0) + 22;
		return array('entries' => $entries, 'cdStart' => $cdStart, 'cdLen' => $cdLen,
			'zip64End' => $zip64End, 'length' => $length);
	}

	private static function centralExtraLen($e) {
		$n = 0;
		if ($e['zip64']) {
			$n += 16; // uncompressed + compressed size
		}
		if (self::$forceZip64 || $e['offsetHdr'] >= self::U32) {
			$n += 8;
		}
		return $n === 0 ? 0 : 4 + $n;
	}

	/**
	 * Write the archive through $out (callable taking bytes, returning false
	 * to stop). Returns the byte count written, or null if $out stopped.
	 */
	public static function write($plan, $out) {
		$written = 0;
		$emit = function ($bytes) use ($out, &$written) {
			$written += strlen($bytes);
			return $out($bytes) !== false;
		};
		$flags = 0x0800; // names are UTF-8
		foreach ($plan['entries'] as $i => $e) {
			if ($e['crc'] === null) {
				$e['crc'] = self::blobCrc($e['path']);
				$plan['entries'][$i]['crc'] = $e['crc'];
			}
			$need = $e['zip64'] ? 45 : ($e['method'] === 8 ? 20 : 10);
			$hdr = pack('VvvvvvVVVvv', 0x04034b50, $need, $flags, $e['method'], $e['time'], $e['date'],
				$e['crc'],
				$e['zip64'] ? self::U32 : $e['csize'],
				$e['zip64'] ? self::U32 : $e['usize'],
				strlen($e['name']), $e['zip64'] ? 20 : 0) . $e['name'];
			if ($e['zip64']) {
				$hdr .= pack('vvPP', 0x0001, 16, $e['usize'], $e['csize']);
			}
			if (!$emit($hdr)) {
				return null;
			}
			if (isset($e['data'])) {
				if (!$emit($e['data'])) {
					return null;
				}
			} elseif (!self::copyRange($e['path'], $e['offset'], $e['csize'], $emit)) {
				return null;
			}
		}

		foreach ($plan['entries'] as $e) {
			$need = $e['zip64'] ? 45 : ($e['method'] === 8 ? 20 : 10);
			$offBig = self::$forceZip64 || $e['offsetHdr'] >= self::U32;
			$extraLen = self::centralExtraLen($e);
			$cd = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0300 | 45, $need, $flags, $e['method'],
				$e['time'], $e['date'], $e['crc'],
				$e['zip64'] ? self::U32 : $e['csize'],
				$e['zip64'] ? self::U32 : $e['usize'],
				strlen($e['name']), $extraLen, 0, 0, 0,
				0100644 << 16,
				$offBig ? self::U32 : $e['offsetHdr']) . $e['name'];
			if ($extraLen > 0) {
				$cd .= pack('vv', 0x0001, $extraLen - 4);
				if ($e['zip64']) {
					$cd .= pack('PP', $e['usize'], $e['csize']);
				}
				if ($offBig) {
					$cd .= pack('P', $e['offsetHdr']);
				}
			}
			if (!$emit($cd)) {
				return null;
			}
		}

		$count = count($plan['entries']);
		$end = '';
		if ($plan['zip64End']) {
			$z64At = $plan['cdStart'] + $plan['cdLen'];
			$end .= pack('VPvvVVPPPP', 0x06064b50, 44, 0x0300 | 45, 45, 0, 0,
				$count, $count, $plan['cdLen'], $plan['cdStart']);
			$end .= pack('VVPV', 0x07064b50, 0, $z64At, 1);
			// unzipper (like Go) looks for the ZIP64 records only when the
			// record count or the directory offset is saturated.
			$end .= pack('VvvvvVVv', 0x06054b50, 0, 0, 0xFFFF, 0xFFFF,
				min($plan['cdLen'], self::U32), self::U32, 0);
		} else {
			$end .= pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $plan['cdLen'], $plan['cdStart'], 0);
		}
		if (!$emit($end)) {
			return null;
		}
		return $written;
	}

	private static function copyRange($path, $offset, $len, $emit) {
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			return false;
		}
		if ($offset > 0 && fseek($fh, $offset) !== 0) {
			fclose($fh);
			return false;
		}
		$left = $len;
		while ($left > 0) {
			$chunk = fread($fh, (int)min(1048576, $left));
			if ($chunk === false || $chunk === '') {
				// The length is already promised: pad so the archive stays
				// parseable (the entry fails its CRC check instead).
				fclose($fh);
				MsWorker::log("smz: $path ended early, $left bytes padded");
				while ($left > 0) {
					$n = (int)min(1048576, $left);
					if (!$emit(str_repeat("\0", $n))) {
						return false;
					}
					$left -= $n;
				}
				return true;
			}
			$left -= strlen($chunk);
			if (!$emit($chunk)) {
				fclose($fh);
				return false;
			}
		}
		fclose($fh);
		return true;
	}

	private static function dosTime($ts) {
		$d = getdate($ts);
		$year = max(1980, $d['year']);
		return array(
			($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
			(($year - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
		);
	}
}

/**
 * Minimal ZIP central directory reader for tile archives: lists file
 * entries with the byte range of their (possibly compressed) data, so they
 * can be copied into the .smz unchanged. ZIP64 aware. Refuses (null)
 * encrypted entries, methods other than store/deflate, and unsafe names.
 */
class MsZipReader {

	public static function entries($path) {
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			return null;
		}
		try {
			return self::read($fh, filesize($path), $path);
		} finally {
			fclose($fh);
		}
	}

	private static function read($fh, $size, $path) {
		if ($size < 22) {
			return null;
		}
		$tailLen = (int)min($size, 22 + 65535);
		fseek($fh, $size - $tailLen);
		$tail = fread($fh, $tailLen);
		$pos = strrpos($tail, "PK\x05\x06");
		if ($pos === false || strlen($tail) - $pos < 22) {
			return null;
		}
		$eocd = unpack('Vsig/vdisk/vcdDisk/vdiskCount/vcount/VcdLen/VcdStart', substr($tail, $pos, 20));
		$count = $eocd['count'];
		$cdLen = $eocd['cdLen'];
		$cdStart = $eocd['cdStart'];
		if ($count === 0xFFFF || $cdLen === MsSmz::U32 || $cdStart === MsSmz::U32) {
			$locAt = $size - $tailLen + $pos - 20;
			if ($locAt < 0) {
				return null;
			}
			fseek($fh, $locAt);
			$loc = unpack('Vsig/Vdisk/Pat/Vdisks', fread($fh, 20));
			if ($loc['sig'] !== 0x07064b50) {
				return null;
			}
			fseek($fh, $loc['at']);
			$z = unpack('Vsig/Plen/vmade/vneed/Vdisk/VcdDisk/PdiskCount/Pcount/PcdLen/PcdStart', fread($fh, 56));
			if ($z['sig'] !== 0x06064b50) {
				return null;
			}
			$count = $z['count'];
			$cdLen = $z['cdLen'];
			$cdStart = $z['cdStart'];
		}
		if ($cdStart + $cdLen > $size) {
			return null;
		}
		fseek($fh, $cdStart);
		$cd = $cdLen > 0 ? fread($fh, $cdLen) : '';
		if (strlen($cd) !== $cdLen) {
			return null;
		}

		$out = array();
		$p = 0;
		for ($i = 0; $i < $count; $i++) {
			if ($p + 46 > $cdLen) {
				return null;
			}
			$h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/Voffset',
				substr($cd, $p, 46));
			if ($h['sig'] !== 0x02014b50) {
				return null;
			}
			$name = substr($cd, $p + 46, $h['nameLen']);
			$extra = substr($cd, $p + 46 + $h['nameLen'], $h['extraLen']);
			$p += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];

			$usize = $h['usize'];
			$csize = $h['csize'];
			$offset = $h['offset'];
			if ($usize === MsSmz::U32 || $csize === MsSmz::U32 || $offset === MsSmz::U32) {
				// ZIP64 field holds only the saturated values, in this order.
				$z = self::zip64Extra($extra);
				$need = ($usize === MsSmz::U32 ? 1 : 0) + ($csize === MsSmz::U32 ? 1 : 0) + ($offset === MsSmz::U32 ? 1 : 0);
				if ($z === null || count($z) < $need) {
					return null;
				}
				if ($usize === MsSmz::U32) {
					$usize = array_shift($z);
				}
				if ($csize === MsSmz::U32) {
					$csize = array_shift($z);
				}
				if ($offset === MsSmz::U32) {
					$offset = array_shift($z);
				}
			}
			if (substr($name, -1) === '/') {
				continue; // directory entry
			}
			if (($h['flags'] & 0x0001) || ($h['method'] !== 0 && $h['method'] !== 8)
				|| $name === '' || $name[0] === '/' || strpos($name, '\\') !== false
				|| preg_match('#(^|/)\.\.(/|$)#', $name) || strpos($name, "\0") !== false) {
				return null;
			}

			// Data starts after the local header, whose extra field can
			// differ in length from the central one.
			fseek($fh, $offset);
			$lh = fread($fh, 30);
			if (strlen($lh) !== 30) {
				return null;
			}
			$l = unpack('Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen', $lh);
			if ($l['sig'] !== 0x04034b50) {
				return null;
			}
			$dataAt = $offset + 30 + $l['nameLen'] + $l['extraLen'];
			if ($dataAt + $csize > $size) {
				return null;
			}
			$out[] = array('name' => $name, 'method' => $h['method'], 'crc' => $h['crc'],
				'csize' => $csize, 'usize' => $usize, 'path' => $path, 'offset' => $dataAt);
		}
		return $out;
	}

	/** Values of a ZIP64 extended information field, in order. */
	private static function zip64Extra($extra) {
		$p = 0;
		while ($p + 4 <= strlen($extra)) {
			$f = unpack('vid/vlen', substr($extra, $p, 4));
			if ($f['id'] === 0x0001) {
				$vals = array();
				for ($q = 0; $q + 8 <= $f['len']; $q += 8) {
					$vals[] = unpack('P', substr($extra, $p + 4 + $q, 8))[1];
				}
				return $vals;
			}
			$p += 4 + $f['len'];
		}
		return null;
	}
}
