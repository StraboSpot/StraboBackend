<?php
/**
 * File: OtaPublish.php
 * Description: Publish API for the app developers' publish script
 *              (docs/StraboField_Update_Server/client/ota-publish.mjs).
 *              Every call needs  Authorization: Bearer <publish token>.
 *
 *                GET  /ota/publish/ping                  token check + server settings
 *                POST /ota/publish/assets/missing        {"sha256":[hex,...]} -> {"missing":[...]}
 *                PUT  /ota/publish/assets/<hex>          raw file body, checked against <hex>
 *                POST /ota/publish/releases              store a signed update or directive
 *                GET  /ota/publish/releases              list (?platform ?runtimeVersion ?channel ?limit)
 *                GET  /ota/publish/releases/<pkey>       one release with its exact body
 *                POST /ota/publish/releases/<pkey>/withdraw   {"note": "..."}
 *                POST /ota/publish/releases/<pkey>/restore
 *                GET  /ota/publish/stats                 installs per build, crash reports (?days)
 *
 *              The server never builds or changes a manifest: it checks one
 *              (shape, asset URLs and hashes against stored files, signature
 *              against OTA_CODE_SIGNING_CERT) and stores its exact bytes.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class OtaPublish {

	const MAX_ASSET_BYTES = 209715200;   // 200 MB
	const MAX_BODY_BYTES = 4194304;      // release JSON (manifest + public app config)

	public static function authorize() {
		if (!defined('OTA_PUBLISH_TOKEN_SHA256') || !preg_match('/^[0-9a-f]{64}$/', OTA_PUBLISH_TOKEN_SHA256)) {
			throw new OtaError(503, 'publishing_off', 'Publishing is not set up on this server');
		}
		$auth = null;
		foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $k) {
			if (!empty($_SERVER[$k])) {
				$auth = $_SERVER[$k];
				break;
			}
		}
		if ($auth === null || !preg_match('/^Bearer\s+(\S+)$/', trim($auth), $m)
			|| !hash_equals(OTA_PUBLISH_TOKEN_SHA256, hash('sha256', $m[1]))) {
			usleep(300000);
			header('WWW-Authenticate: Bearer');
			throw new OtaError(401, 'unauthorized', 'A valid publish token is required');
		}
	}

	public static function ping(OtaDb $odb) {
		Ota::json(200, array(
			'ok' => true,
			'publicBase' => Ota::publicBase(),
			'assetUrlPrefix' => Ota::publicBase() . '/ota/assets/',
			'certificateInstalled' => Ota::signingKey() !== null,
			'keyid' => Ota::keyId(),
		));
	}

	public static function missing(OtaDb $odb) {
		$in = Ota::readJson(1048576);
		if (!isset($in['sha256']) || !is_array($in['sha256']) || count($in['sha256']) > 5000) {
			throw new OtaError(400, 'bad_request', 'sha256 must be a list of up to 5000 hashes');
		}
		$missing = array();
		foreach (array_unique($in['sha256']) as $h) {
			if (!is_string($h) || !preg_match('/^[0-9a-f]{64}$/', $h)) {
				throw new OtaError(400, 'bad_hash', 'Each hash must be 64 lowercase hex characters');
			}
			$have = $odb->val("SELECT 1 FROM ota_assets WHERE sha256 = $1", array($h));
			if ($have === null || !is_file(Ota::assetPath($h))) {
				$missing[] = $h;
			}
		}
		Ota::json(200, array('missing' => $missing));
	}

	public static function putAsset(OtaDb $odb, $hex) {
		$len = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : -1;
		if ($len > self::MAX_ASSET_BYTES) {
			throw new OtaError(413, 'too_large', 'Files are limited to ' . self::MAX_ASSET_BYTES . ' bytes');
		}
		$final = Ota::assetPath($hex);
		$dir = dirname($final);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new OtaError(500, 'storage', 'Could not create the storage folder');
		}
		$tmp = $dir . '/.up-' . bin2hex(random_bytes(8));
		$in = fopen('php://input', 'rb');
		$out = fopen($tmp, 'wb');
		if ($out === false) {
			throw new OtaError(500, 'storage', 'Could not write the file');
		}
		$ctx = hash_init('sha256');
		$bytes = 0;
		while (!feof($in)) {
			$chunk = fread($in, 1048576);
			if ($chunk === false) {
				break;
			}
			$bytes += strlen($chunk);
			if ($bytes > self::MAX_ASSET_BYTES) {
				fclose($out);
				@unlink($tmp);
				throw new OtaError(413, 'too_large', 'Files are limited to ' . self::MAX_ASSET_BYTES . ' bytes');
			}
			hash_update($ctx, $chunk);
			fwrite($out, $chunk);
		}
		fclose($out);
		$got = hash_final($ctx);
		if ($len >= 0 && $bytes !== $len) {
			@unlink($tmp);
			throw new OtaError(400, 'incomplete', "Received $bytes of $len bytes");
		}
		if ($got !== $hex) {
			@unlink($tmp);
			throw new OtaError(400, 'hash_mismatch', "The file's SHA-256 is $got, not $hex");
		}
		if (!@rename($tmp, $final)) {
			@unlink($tmp);
			throw new OtaError(500, 'storage', 'Could not store the file');
		}
		@chmod($final, 0644);
		$odb->q("INSERT INTO ota_assets (sha256, bytes) VALUES ($1, $2)
		         ON CONFLICT (sha256) DO UPDATE SET bytes = EXCLUDED.bytes", array($hex, $bytes));
		Ota::json(201, array('sha256' => $hex, 'bytes' => $bytes));
	}

	/** Check one manifest asset entry; returns [hex, ext, contentType]. */
	private static function checkAsset($a, $where) {
		if (!is_array($a)) {
			throw new OtaError(400, 'bad_manifest', "$where is not an object");
		}
		foreach (array('hash', 'key', 'contentType', 'url') as $f) {
			if (!isset($a[$f]) || !is_string($a[$f]) || $a[$f] === '') {
				throw new OtaError(400, 'bad_manifest', "$where.$f is missing");
			}
		}
		if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $a['hash'])) {
			throw new OtaError(400, 'bad_manifest', "$where.hash must be base64url SHA-256 without padding");
		}
		$hex = bin2hex(base64_decode(strtr($a['hash'], '-_', '+/') . '='));
		if (strlen($a['key']) > 128) {
			throw new OtaError(400, 'bad_manifest', "$where.key is too long");
		}
		if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $a['contentType']) || strlen($a['contentType']) > 128) {
			throw new OtaError(400, 'bad_manifest', "$where.contentType is not a MIME type");
		}
		$prefix = Ota::publicBase() . '/ota/assets/' . $hex;
		if (strpos($a['url'], $prefix) !== 0 || !preg_match('/^(\.[A-Za-z0-9]{1,10})?$/', substr($a['url'], strlen($prefix)))) {
			throw new OtaError(400, 'bad_asset_url',
				"$where.url must be $prefix followed by an optional extension (got {$a['url']})");
		}
		return array($hex, substr($a['url'], strlen($prefix)), $a['contentType']);
	}

	private static function verifySignature($body, $sig) {
		$key = Ota::signingKey();
		if ($key === null) {
			if (defined('OTA_ALLOW_UNSIGNED') && OTA_ALLOW_UNSIGNED === true) {
				return; // local tests only: any signature is stored as given
			}
			throw new OtaError(503, 'signing_not_set_up',
				'No code signing certificate is set up on this server, so nothing can be published');
		}
		if (!is_string($sig) || $sig === '') {
			throw new OtaError(400, 'signature_required', 'This server requires a signature on every release');
		}
		$raw = base64_decode($sig, true);
		if ($raw === false || openssl_verify($body, $raw, $key, OPENSSL_ALGO_SHA256) !== 1) {
			throw new OtaError(400, 'bad_signature', 'The signature does not match the code signing certificate');
		}
	}

	private static function parseTime($s, $what) {
		if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $s)) {
			throw new OtaError(400, 'bad_time', "$what must be an ISO 8601 date-time with a time zone");
		}
		$t = strtotime($s);
		if ($t === false) {
			throw new OtaError(400, 'bad_time', "$what is not a valid date-time");
		}
		if ($t > time() + 3600) {
			throw new OtaError(400, 'bad_time', "$what is in the future (check the publishing computer's clock)");
		}
		return $s;
	}

	public static function createRelease(OtaDb $odb) {
		$in = Ota::readJson(self::MAX_BODY_BYTES);
		$kind = isset($in['kind']) ? $in['kind'] : null;
		$platform = isset($in['platform']) ? $in['platform'] : null;
		$runtime = isset($in['runtimeVersion']) ? $in['runtimeVersion'] : null;
		$channel = isset($in['channel']) ? $in['channel'] : Ota::DEFAULT_CHANNEL;
		$body = isset($in['body']) ? $in['body'] : null;
		$sig = isset($in['signature']) && $in['signature'] !== '' ? $in['signature'] : null;
		$message = isset($in['message']) && is_string($in['message']) ? mb_substr($in['message'], 0, 2000) : null;

		if ($kind !== 'update' && $kind !== 'rollBackToEmbedded') {
			throw new OtaError(400, 'bad_kind', 'kind must be update or rollBackToEmbedded');
		}
		if ($platform !== 'ios' && $platform !== 'android') {
			throw new OtaError(400, 'bad_platform', 'platform must be ios or android');
		}
		if (!is_string($runtime) || !preg_match(Ota::RUNTIME_RE, $runtime)) {
			throw new OtaError(400, 'bad_runtime_version', 'runtimeVersion is missing or not valid');
		}
		if (!is_string($channel) || !preg_match(Ota::CHANNEL_RE, $channel)) {
			throw new OtaError(400, 'bad_channel', 'channel is not valid');
		}
		if (!is_string($body)) {
			throw new OtaError(400, 'bad_body', 'body must be the exact JSON text that was signed');
		}
		if ($sig !== null && !is_string($sig)) {
			throw new OtaError(400, 'bad_signature', 'signature must be base64 text');
		}
		$doc = json_decode($body, true);
		if (!is_array($doc)) {
			throw new OtaError(400, 'bad_body', 'body is not a JSON object');
		}
		self::verifySignature($body, $sig);

		$assets = array();
		$updateId = null;
		if ($kind === 'update') {
			if (!isset($doc['id']) || !is_string($doc['id']) || !preg_match(Ota::UUID_RE, $doc['id'])) {
				throw new OtaError(400, 'bad_manifest', 'id must be a lowercase UUID');
			}
			$updateId = $doc['id'];
			$createdAt = self::parseTime(isset($doc['createdAt']) ? $doc['createdAt'] : null, 'createdAt');
			if (!isset($doc['runtimeVersion']) || $doc['runtimeVersion'] !== $runtime) {
				throw new OtaError(400, 'bad_manifest', 'The manifest runtimeVersion does not match runtimeVersion');
			}
			if (isset($doc['metadata'])) {
				if (!is_array($doc['metadata'])) {
					throw new OtaError(400, 'bad_manifest', 'metadata must be an object of strings');
				}
				foreach ($doc['metadata'] as $v) {
					if (!is_string($v)) {
						throw new OtaError(400, 'bad_manifest', 'metadata must be an object of strings');
					}
				}
			}
			if (!isset($doc['launchAsset'])) {
				throw new OtaError(400, 'bad_manifest', 'launchAsset is missing');
			}
			$launch = self::checkAsset($doc['launchAsset'], 'launchAsset');
			$launch[] = true;
			$assets[] = $launch;
			if (!isset($doc['assets']) || !is_array($doc['assets']) || !array_is_list($doc['assets'])) {
				throw new OtaError(400, 'bad_manifest', 'assets must be a list');
			}
			foreach ($doc['assets'] as $i => $a) {
				$x = self::checkAsset($a, "assets[$i]");
				$x[] = false;
				$assets[] = $x;
			}
			$missing = array();
			foreach ($assets as $a) {
				if ($odb->val("SELECT 1 FROM ota_assets WHERE sha256 = $1", array($a[0])) === null || !is_file(Ota::assetPath($a[0]))) {
					$missing[] = $a[0];
				}
			}
			if ($missing) {
				throw new OtaError(409, 'assets_missing', 'Upload these files first: ' . implode(', ', array_unique($missing)));
			}
			if ($odb->val("SELECT 1 FROM ota_releases WHERE update_id = $1", array($updateId)) !== null) {
				throw new OtaError(409, 'duplicate_id', 'An update with this id was already published; every publish needs a new id');
			}
		} else {
			if (!isset($doc['type']) || $doc['type'] !== 'rollBackToEmbedded') {
				throw new OtaError(400, 'bad_directive', 'The directive type must be rollBackToEmbedded');
			}
			$createdAt = self::parseTime(isset($doc['parameters']['commitTime']) ? $doc['parameters']['commitTime'] : null,
				'parameters.commitTime');
		}

		$newest = $odb->row(
			"SELECT pkey, " . OtaDb::iso('created_at') . " AS created_at, (created_at >= $4::timestamptz) AS not_older
			   FROM ota_releases WHERE platform = $1 AND runtime_version = $2 AND channel = $3 AND withdrawn_at IS NULL
			  ORDER BY created_at DESC, pkey DESC LIMIT 1",
			array($platform, $runtime, $channel, $createdAt));
		if ($newest !== null && $newest['not_older'] === 't') {
			throw new OtaError(409, 'not_newest',
				"Release {$newest['pkey']} is dated {$newest['created_at']}; a new release must be dated later or phones would ignore it");
		}

		$odb->begin();
		try {
			$pkey = $odb->val(
				"INSERT INTO ota_releases (kind, update_id, platform, runtime_version, channel, created_at, body,
				     signature, message, published_from)
				 VALUES ($1, $2, $3, $4, $5, $6::timestamptz, $7, $8, $9, $10) RETURNING pkey",
				array($kind, $updateId, $platform, $runtime, $channel, $createdAt, $body, $sig, $message, Ota::clientIp()));
			foreach ($assets as $a) {
				$odb->q(
					"INSERT INTO ota_release_assets (release_pkey, sha256, ext, content_type, is_launch)
					 VALUES ($1, $2, $3, $4, $5) ON CONFLICT DO NOTHING",
					array($pkey, $a[0], $a[1], $a[2], $a[3] ? 't' : 'f'));
			}
			$odb->commit();
		} catch (Throwable $e) {
			$odb->rollback();
			throw $e;
		}
		Ota::json(201, self::one($odb, (int)$pkey, false));
	}

	private static function one(OtaDb $odb, $pkey, $withBody) {
		$r = $odb->row(
			"SELECT pkey, kind, update_id, platform, runtime_version, channel, " . OtaDb::iso('created_at') . " AS created_at,
			        " . OtaDb::iso('published_at') . " AS published_at, " . OtaDb::iso('withdrawn_at') . " AS withdrawn_at,
			        withdrawn_note, message, signature IS NOT NULL AS signed, body,
			        (SELECT count(*) FROM ota_release_assets a WHERE a.release_pkey = r.pkey) AS files,
			        (SELECT coalesce(sum(s.bytes), 0) FROM ota_release_assets a JOIN ota_assets s USING (sha256)
			          WHERE a.release_pkey = r.pkey) AS bytes
			   FROM ota_releases r WHERE pkey = $1", array($pkey));
		if ($r === null) {
			throw new OtaError(404, 'not_found', 'No such release');
		}
		return self::shape($r, $withBody);
	}

	private static function shape($r, $withBody) {
		$out = array(
			'releaseId' => (int)$r['pkey'],
			'kind' => $r['kind'],
			'updateId' => $r['update_id'],
			'platform' => $r['platform'],
			'runtimeVersion' => $r['runtime_version'],
			'channel' => $r['channel'],
			'createdAt' => $r['created_at'],
			'publishedAt' => $r['published_at'],
			'withdrawnAt' => $r['withdrawn_at'],
			'withdrawnNote' => $r['withdrawn_note'],
			'message' => $r['message'],
			'signed' => $r['signed'] === 't',
			'files' => (int)$r['files'],
			'bytes' => (int)$r['bytes'],
		);
		if ($withBody) {
			$out['body'] = $r['body'];
		}
		return $out;
	}

	public static function getRelease(OtaDb $odb, $pkey) {
		Ota::json(200, self::one($odb, (int)$pkey, true));
	}

	public static function listReleases(OtaDb $odb) {
		$where = array();
		$params = array();
		foreach (array('platform' => 'platform', 'runtimeVersion' => 'runtime_version', 'channel' => 'channel') as $q => $col) {
			if (isset($_GET[$q]) && $_GET[$q] !== '') {
				$params[] = (string)$_GET[$q];
				$where[] = "$col = $" . count($params);
			}
		}
		$limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 50;
		$rows = $odb->rows(
			"SELECT pkey, kind, update_id, platform, runtime_version, channel, " . OtaDb::iso('created_at') . " AS created_at,
			        " . OtaDb::iso('published_at') . " AS published_at, " . OtaDb::iso('withdrawn_at') . " AS withdrawn_at,
			        withdrawn_note, message, signature IS NOT NULL AS signed,
			        (SELECT count(*) FROM ota_release_assets a WHERE a.release_pkey = r.pkey) AS files,
			        (SELECT coalesce(sum(s.bytes), 0) FROM ota_release_assets a JOIN ota_assets s USING (sha256)
			          WHERE a.release_pkey = r.pkey) AS bytes
			   FROM ota_releases r" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
			  ORDER BY created_at DESC, pkey DESC LIMIT $limit", $params);
		$out = array();
		foreach ($rows as $r) {
			$out[] = self::shape($r, false);
		}
		Ota::json(200, array('releases' => $out));
	}

	public static function withdraw(OtaDb $odb, $pkey) {
		$note = null;
		if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
			$in = Ota::readJson(65536);
			$note = isset($in['note']) && is_string($in['note']) ? mb_substr($in['note'], 0, 2000) : null;
		}
		$n = pg_affected_rows($odb->q(
			"UPDATE ota_releases SET withdrawn_at = coalesce(withdrawn_at, now()), withdrawn_note = coalesce($2, withdrawn_note)
			  WHERE pkey = $1", array((int)$pkey, $note)));
		if ($n === 0) {
			throw new OtaError(404, 'not_found', 'No such release');
		}
		Ota::json(200, self::one($odb, (int)$pkey, false));
	}

	public static function restore(OtaDb $odb, $pkey) {
		$n = pg_affected_rows($odb->q(
			"UPDATE ota_releases SET withdrawn_at = NULL, withdrawn_note = NULL WHERE pkey = $1", array((int)$pkey)));
		if ($n === 0) {
			throw new OtaError(404, 'not_found', 'No such release');
		}
		Ota::json(200, self::one($odb, (int)$pkey, false));
	}

	public static function stats(OtaDb $odb) {
		$days = isset($_GET['days']) ? max(1, min(400, (int)$_GET['days'])) : 30;
		$builds = $odb->rows(
			"SELECT platform, runtime_version, channel, count(DISTINCT client_hash) AS installs, sum(requests) AS requests,
			        max(day)::text AS last_day
			   FROM ota_daily_clients WHERE day > current_date - $1::int
			  GROUP BY platform, runtime_version, channel ORDER BY installs DESC", array($days));
		$running = $odb->rows(
			"SELECT DISTINCT ON (client_hash) platform, runtime_version, channel, current_update_id
			   FROM ota_daily_clients WHERE day > current_date - $1::int ORDER BY client_hash, day DESC", array($days));
		$byUpdate = array();
		foreach ($running as $r) {
			$k = $r['platform'] . '|' . $r['runtime_version'] . '|' . $r['channel'] . '|' . (string)$r['current_update_id'];
			if (!isset($byUpdate[$k])) {
				$byUpdate[$k] = array('platform' => $r['platform'], 'runtimeVersion' => $r['runtime_version'],
					'channel' => $r['channel'], 'currentUpdateId' => $r['current_update_id'], 'installs' => 0);
			}
			$byUpdate[$k]['installs']++;
		}
		$errors = $odb->rows(
			"SELECT " . OtaDb::iso('received_at') . " AS received_at, platform, runtime_version, channel, current_update_id,
			        embedded_update_id, error
			   FROM ota_fatal_errors WHERE received_at > now() - ($1::int * interval '1 day')
			  ORDER BY received_at DESC LIMIT 200", array($days));
		$outBuilds = array();
		foreach ($builds as $b) {
			$outBuilds[] = array('platform' => $b['platform'], 'runtimeVersion' => $b['runtime_version'],
				'channel' => $b['channel'], 'installs' => (int)$b['installs'], 'requests' => (int)$b['requests'],
				'lastDay' => $b['last_day']);
		}
		Ota::json(200, array('days' => $days, 'builds' => $outBuilds, 'runningUpdates' => array_values($byUpdate),
			'fatalErrors' => $errors));
	}
}
