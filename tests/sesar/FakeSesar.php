<?php
/**
 * File: tests/sesar/FakeSesar.php
 * Description: In-process fake of the SESAR API for the tests/sesar suites
 *              (a SesarTransport, so SesarClient runs unchanged). Behaviour
 *              mirrors what the Phase 1 sandbox probes showed:
 *              - auth/token/{connection}/ wants a FORM `token` = ORCID
 *                id_token; unknown token -> 401 errors.token; user without
 *                upload permission -> 401 errors.permissions.
 *              - refresh ROTATES: the presented refresh token is blacklisted
 *                and a new pair issued; reusing it -> 401 {detail, code}.
 *              - samples keyed by IGSN "10.58052/<CODE><n>", list responses
 *                in {count,next,previous,data}, numbers as strings,
 *                object_type returned as the hierarchical path.
 *
 *              State lives in a JSON file guarded by flock, so child
 *              processes (the concurrent-refresh test) share one SESAR.
 *              Knobs: $fake->set('refresh_delay_us', n), 'fail_next' =>
 *              {path fragment: status|0}, 'register_then_timeout' => true.
 *
 * @package    StraboSpot Tests
 */

require_once '/srv/app/www/includes/sesar/SesarClient.php';

class FakeSesar implements SesarTransport
{
	public $stateFile;

	public function __construct($stateFile, $reset = false)
	{
		$this->stateFile = $stateFile;
		if ($reset || !is_file($stateFile)) {
			file_put_contents($stateFile, json_encode(self::emptyState()));
			@chmod($stateFile, 0666);
		}
	}

	public static function emptyState()
	{
		return array(
			'orcid_users' => new stdClass(),   // id_token => {orcid, upload}
			'tokens'      => new stdClass(),   // token => {kind, orcid, exp, blacklisted}
			'seq'         => 0,
			'samples'     => new stdClass(),   // igsn => record
			'calls'       => array(),
			'knobs'       => new stdClass(),
		);
	}

	// ---- test setup / inspection -----------------------------------------

	public function addOrcidUser($idToken, $orcid, $uploadPermission = true)
	{
		$this->mutate(function (&$s) use ($idToken, $orcid, $uploadPermission) {
			$s['orcid_users'][$idToken] = array('orcid' => $orcid, 'upload' => $uploadPermission);
		});
	}

	public function set($knob, $value)
	{
		$this->mutate(function (&$s) use ($knob, $value) { $s['knobs'][$knob] = $value; });
	}

	public function state() { return json_decode(file_get_contents($this->stateFile), true); }

	public function calls($fragment = null)
	{
		$c = $this->state()['calls'];
		if ($fragment === null) return $c;
		return array_values(array_filter($c, function ($x) use ($fragment) { return strpos($x['url'], $fragment) !== false; }));
	}

	/** Expire every access token (forces the next accessToken() to refresh). */
	public function expireAccessTokens()
	{
		$this->mutate(function (&$s) {
			foreach ($s['tokens'] as $t => $v) if ($v['kind'] === 'access') $s['tokens'][$t]['exp'] = time() - 10;
		});
	}

	/** Server-side edit (someone changed the record at SESAR). */
	public function editAtSesar($igsn, array $fields)
	{
		$this->mutate(function (&$s) use ($igsn, $fields) {
			foreach ($fields as $k => $v) $s['samples'][$igsn][$k] = $v;
			$s['samples'][$igsn]['last_update_date'] = gmdate('Y-m-d\TH:i:s\Z', time() + 1);
		});
	}

	// ---- transport ---------------------------------------------------------

	public function send($method, $url, array $headers, $body)
	{
		$self = $this;
		$result = null;
		$this->mutate(function (&$s) use ($self, $method, $url, $headers, $body, &$result) {
			$s['calls'][] = array('t' => microtime(true), 'pid' => getmypid(), 'method' => $method, 'url' => $url, 'body' => $body,
				'content_type' => self::header($headers, 'Content-Type'));
			$result = $self->route($s, $method, $url, $headers, $body);
		});
		// Delay OUTSIDE the lock so concurrent refreshers really overlap.
		if (!empty($result[2])) usleep($result[2]);
		return array($result[0], $result[1]);
	}

	/** @return array [status, body, delay_us] */
	public function route(&$s, $method, $url, array $headers, $body)
	{
		$path = preg_replace('#^https://[^/]+/api/#', '', parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH));
		$query = array();
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

		foreach ((array)(isset($s['knobs']['fail_next']) ? $s['knobs']['fail_next'] : array()) as $frag => $status) {
			if (strpos($path, $frag) !== false) {
				unset($s['knobs']['fail_next'][$frag]);
				return array((int)$status, $status ? json_encode(array('message' => 'Injected failure', 'errors' => array())) : '', 0);
			}
		}

		// -- auth -------------------------------------------------------------
		if ($method === 'POST' && preg_match('#^auth/token/([^/]+)/$#', $path, $m) && $m[1] !== 'refresh') {
			if (strpos((string)self::header($headers, 'Content-Type'), 'x-www-form-urlencoded') === false) {
				return self::err(401, 'token', 'The given ORCID JWT is either invalid or no associated user was found.');
			}
			parse_str((string)$body, $f);
			$u = isset($f['token'], $s['orcid_users'][$f['token']]) ? $s['orcid_users'][$f['token']] : null;
			if ($u === null) return self::err(401, 'token', 'The given ORCID JWT is either invalid or no associated user was found.');
			if (!$u['upload']) return self::err(401, 'permissions', 'You do not have permission to generate a JWT.');
			return array(201, json_encode(array('data' => self::issue($s, $u['orcid'], urldecode($m[1])))), 0);
		}
		if ($method === 'POST' && $path === 'auth/token/refresh/') {
			parse_str((string)$body, $f);
			$r = isset($f['refresh']) ? $f['refresh'] : '';
			$t = isset($s['tokens'][$r]) ? $s['tokens'][$r] : null;
			if ($t === null || $t['kind'] !== 'refresh' || $t['blacklisted'] || $t['exp'] < time()) {
				return array(401, json_encode(array('detail' => 'Token is blacklisted', 'code' => 'token_not_valid')), 0);
			}
			$s['tokens'][$r]['blacklisted'] = true;
			$pair = self::issue($s, $t['orcid'], $t['connection']);
			$delay = isset($s['knobs']['refresh_delay_us']) ? (int)$s['knobs']['refresh_delay_us'] : 0;
			return array(200, json_encode(array('data' => $pair)), $delay);
		}

		// -- public vocab -----------------------------------------------------
		if ($method === 'GET' && $path === 'vocab/object-types/') {
			return array(200, json_encode(array('data' => array(
				array('id' => 1, 'label' => 'Material sample', 'hierarchical_label' => 'Material sample'),
				array('id' => 2, 'label' => 'Individual sample', 'hierarchical_label' => 'General sample types > Individual sample'),
				array('id' => 3, 'label' => 'Rock hand sample', 'hierarchical_label' => 'General sample types > Rock hand sample'),
				array('id' => 4, 'label' => 'Core', 'hierarchical_label' => 'Marine and lacustrine samples > Core'),
				array('id' => 5, 'label' => 'Thin section', 'hierarchical_label' => 'Analytical preparations > Sectioned specimen > Thin section'),
				array('id' => 6, 'label' => 'Oriented Core', 'hierarchical_label' => 'General sample types > Oriented Core'),
			))), 0);
		}
		if ($method === 'GET' && $path === 'vocab/material-types/') {
			return array(200, json_encode(array('data' => array(
				// Real shape (sandbox 09-26): broad categories are for_registration false.
				array('id' => 1, 'label' => 'Rock', 'for_registration' => false),
				array('id' => 2, 'label' => 'Sediment', 'for_registration' => true),
				array('id' => 3, 'label' => 'Tephra', 'for_registration' => true),
				array('id' => 4, 'label' => 'Biological material', 'for_registration' => true),
				array('id' => 5, 'label' => 'Limestone', 'for_registration' => true),
				array('id' => 6, 'label' => 'Granite', 'for_registration' => true),
				array('id' => 7, 'label' => 'Dolomite', 'for_registration' => true, 'alt_label' => 'dolostone, pure dolomitic or magnesian carbonate sedimentary rock,'),
				array('id' => 8, 'label' => 'Igneous rock', 'for_registration' => false),
			))), 0);
		}

		// -- everything else needs a valid access token ------------------------
		$bearer = preg_replace('/^Bearer\s+/', '', (string)self::header($headers, 'Authorization'));
		$tok = ($bearer !== '' && isset($s['tokens'][$bearer])) ? $s['tokens'][$bearer] : null;
		$authed = $tok !== null && $tok['kind'] === 'access' && $tok['exp'] >= time();
		if ($bearer !== '' && !$authed) return array(401, json_encode(array('detail' => 'Given token not valid for any token type', 'code' => 'token_not_valid')), 0);

		if ($method === 'GET' && $path === 'auth/user/') {
			return array(200, json_encode(array('data' => array('orcid' => $tok['orcid'], 'name' => 'Fake User ' . $tok['orcid'],
				'jwt_connection' => strtoupper($tok['connection'])))), 0);
		}
		if ($method === 'GET' && $path === 'sesar-codes/by-permission/') {
			return array(200, json_encode(array(array('code' => 'IEFAK', 'name' => 'Fake code'))), 0);
		}
		if ($method === 'POST' && $path === 'samples/') {
			if (!$authed) return self::err(401, 'detail', 'Authentication credentials were not provided.');
			$p = json_decode((string)$body, true);
			foreach (array('name', 'object_type', 'sesar_code', 'latitude', 'longitude') as $req) {
				if (!isset($p[$req]) || $p[$req] === '') return self::err(400, $req, 'This field is required.');
			}
			if (!empty($p['parent_sample']) && !isset($s['samples'][$p['parent_sample']])) {
				return self::err(400, 'parent_sample', 'Parent sample not found.');
			}
			$s['seq']++;
			$igsn = '10.58052/' . $p['sesar_code'] . str_pad((string)$s['seq'], 4, '0', STR_PAD_LEFT);
			$rec = self::toRecord($p, $igsn, $tok['orcid']);
			$s['samples'][$igsn] = $rec;
			if (!empty($s['knobs']['register_then_timeout'])) {
				unset($s['knobs']['register_then_timeout']);
				return array(0, '', 0);   // registered at SESAR, but the response is lost
			}
			return array(201, json_encode(array('data' => $rec)), 0);
		}
		if (preg_match('#^samples/(10\.58052/[^/]+)/deactivate/$#', $path, $m) && $method === 'POST') {
			$igsn = urldecode($m[1]);
			if (!isset($s['samples'][$igsn])) return self::err(404, 'detail', 'Not found.');
			$b = json_decode((string)$body, true);
			if (empty($b['deactivate_reason'])) return self::err(400, 'deactivate_reason', 'This field is required.');
			$s['samples'][$igsn]['deactivation_requested'] = $b;
			return array(200, json_encode(array('data' => array('igsn' => $igsn, 'status' => 'requested'))), 0);
		}
		if (preg_match('#^samples/(10\.58052/[^/]+)/$#', $path, $m)) {
			$igsn = urldecode($m[1]);
			if (!isset($s['samples'][$igsn])) return self::err(404, 'detail', 'Not found.');
			if (!empty($s['samples'][$igsn]['_deactivated'])) return self::err(410, 'detail', 'This sample has been deactivated.');
			if ($method === 'GET') return array(200, json_encode(array('data' => $s['samples'][$igsn])), 0);
			if ($method === 'PATCH') {
				if (!$authed || $s['samples'][$igsn]['_owner'] !== $tok['orcid']) return self::err(403, 'detail', 'You do not have permission to perform this action.');
				foreach (json_decode((string)$body, true) as $k => $v) {
					if (in_array($k, array('igsn', 'sesar_code'), true)) return self::err(400, $k, 'Cannot be changed.');
					$s['samples'][$igsn][$k] = self::outValue($k, $v);
				}
				$s['samples'][$igsn]['last_update_date'] = gmdate('Y-m-d\TH:i:s\Z');
				return array(200, json_encode(array('data' => $s['samples'][$igsn])), 0);
			}
		}
		if ($method === 'GET' && $path === 'samples/') {
			$rows = array();
			foreach ($s['samples'] as $igsn => $rec) {
				if (isset($query['external_sample_id']) && (string)$rec['external_sample_id'] !== (string)$query['external_sample_id']) continue;
				if ($authed && $rec['_owner'] !== $tok['orcid']) continue;   // scope=personal
				$rows[] = $rec;
			}
			return array(200, json_encode(array('count' => count($rows), 'next' => null, 'previous' => null, 'data' => $rows,
				'page_size' => 100, 'current_page' => 1, 'total_pages' => 1)), 0);
		}
		return self::err(404, 'detail', 'Fake SESAR: no route for ' . $method . ' ' . $path);
	}

	// ---- internals -----------------------------------------------------------

	private function mutate($fn)
	{
		$fh = fopen($this->stateFile, 'c+');
		flock($fh, LOCK_EX);
		$raw = stream_get_contents($fh);
		$s = json_decode($raw === '' ? json_encode(self::emptyState()) : $raw, true);
		foreach (array('orcid_users', 'tokens', 'samples', 'knobs') as $k) if (!isset($s[$k]) || !is_array($s[$k])) $s[$k] = array();
		$fn($s);
		foreach (array('orcid_users', 'tokens', 'samples', 'knobs') as $k) if (empty($s[$k])) $s[$k] = new stdClass();
		ftruncate($fh, 0); rewind($fh);
		fwrite($fh, json_encode($s));
		fflush($fh);
		flock($fh, LOCK_UN);
		fclose($fh);
	}

	private static function issue(&$s, $orcid, $connection)
	{
		$pair = array();
		foreach (array('refresh' => 31536000, 'access' => 86400) as $kind => $life) {
			$exp = time() + $life;
			$jwt = self::b64(json_encode(array('alg' => 'none'))) . '.' . self::b64(json_encode(array(
				'exp' => $exp, 'kind' => $kind, 'connection' => $connection, 'jti' => bin2hex(random_bytes(8))))) . '.sig';
			$s['tokens'][$jwt] = array('kind' => $kind, 'orcid' => $orcid, 'connection' => $connection, 'exp' => $exp, 'blacklisted' => false);
			$pair[$kind] = $jwt;
		}
		return $pair;
	}

	private static function toRecord(array $p, $igsn, $owner)
	{
		$paths = array('Individual sample' => 'General sample types > Individual sample',
			'Rock hand sample' => 'General sample types > Rock hand sample', 'Core' => 'Marine and lacustrine samples > Core');
		$rec = array('igsn' => $igsn, '_owner' => $owner, 'metadata_store_status' => 'registered-datacite',
			'is_draft' => false, 'is_pending_review' => false, 'last_update_date' => gmdate('Y-m-d\TH:i:s\Z'));
		foreach ($p as $k => $v) $rec[$k] = self::outValue($k, $v);
		if (isset($paths[$p['object_type']])) $rec['object_type'] = $paths[$p['object_type']];
		foreach (array('sample_description', 'purpose', 'parent_sample', 'general_material_type', 'sampling_start_date', 'external_sample_id') as $k) {
			if (!isset($rec[$k])) $rec[$k] = null;
		}
		return $rec;
	}

	/** SESAR returns decimals as strings with its own formatting. */
	private static function outValue($k, $v)
	{
		if (in_array($k, array('latitude', 'longitude', 'latitude_end', 'longitude_end'), true) && is_numeric($v)) {
			return number_format((float)$v, 8, '.', '');
		}
		return $v;
	}

	private static function err($status, $field, $msg)
	{
		return array($status, json_encode(array('message' => $msg, 'errors' => array($field => array($msg)))), 0);
	}

	private static function header(array $headers, $name)
	{
		foreach ($headers as $h) {
			if (stripos($h, $name . ':') === 0) return trim(substr($h, strlen($name) + 1));
		}
		return null;
	}

	private static function b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
}
