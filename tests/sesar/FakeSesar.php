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
 *              - (Phase 3) auth/user/ in the real {data:{user:{individual,
 *                email, orcid}}} shape; per-ORCID SESAR codes; POST
 *                sesar-codes/ with the real BARE 400 field dicts; POST
 *                api-access-request/ recorded in state['access_requests'].
 *
 *              - (Phase 4) public GET samples/by-igsn/ (404 unknown, 410
 *                deactivated, 403 private), POST related-resources/, SESAR's
 *                integer sample_id on list rows only (the POST and detail
 *                answers lack it, as on the sandbox), unregistrable
 *                materials refused like the sandbox ("Rock").
 *
 *              Knobs: $fake->set('refresh_delay_us', n), 'fail_next' =>
 *              {path fragment: status|0}, 'register_then_timeout' => true.
 *              Setup: seedSample(igsn, fields), markDeactivated(igsn).
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
			'codes'       => new stdClass(),   // orcid => [sesar codes]
			'access_requests' => array(),
			'tokens'      => new stdClass(),   // token => {kind, orcid, exp, blacklisted}
			'seq'         => 0,
			'samples'     => new stdClass(),   // igsn => record
			'calls'       => array(),
			'knobs'       => new stdClass(),
		);
	}

	// ---- test setup / inspection -----------------------------------------

	public function addOrcidUser($idToken, $orcid, $uploadPermission = true, $codes = array('IEFAK'))
	{
		$this->mutate(function (&$s) use ($idToken, $orcid, $uploadPermission, $codes) {
			$s['orcid_users'][$idToken] = array('orcid' => $orcid, 'upload' => $uploadPermission);
			if (!isset($s['codes'][$orcid])) $s['codes'][$orcid] = $codes;
		});
	}

	/** The user pressed "Revoke All Active JWTs" (or SESAR blacklisted them). */
	public function revokeTokensFor($orcid)
	{
		$this->mutate(function (&$s) use ($orcid) {
			foreach ($s['tokens'] as $t => $v) if ($v['orcid'] === $orcid) $s['tokens'][$t]['blacklisted'] = true;
		});
	}

	/** SESAR staff approve an API access request. */
	public function grantUpload($orcid)
	{
		$this->mutate(function (&$s) use ($orcid) {
			foreach ($s['orcid_users'] as $t => $u) if ($u['orcid'] === $orcid) $s['orcid_users'][$t]['upload'] = true;
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
	/** A record that exists at SESAR but was not made by these tests' users. */
	public function seedSample($igsn, array $fields = array())
	{
		$this->mutate(function (&$s) use ($igsn, $fields) {
			$s['sample_seq'] = (isset($s['sample_seq']) ? $s['sample_seq'] : 900000) + 1;
			$s['samples'][$igsn] = array_merge(array('igsn' => $igsn, '_owner' => 'someone-else', '_sample_id' => $s['sample_seq'],
				'name' => 'Seeded', 'external_sample_id' => null, 'metadata_store_status' => 'registered-datacite'), $fields);
		});
	}

	public function markDeactivated($igsn)
	{
		$this->mutate(function (&$s) use ($igsn) { $s['samples'][$igsn]['_deactivated'] = true; });
	}

	/** A curator denies a pending deactivation request (the record stays live). */
	public function denyDeactivation($igsn)
	{
		$this->mutate(function (&$s) use ($igsn) { unset($s['samples'][$igsn]['deactivation_requested']); });
	}

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

		// -- anonymous: the API access request form (emails SESAR staff) -------
		if ($method === 'POST' && $path === 'api-access-request/') {
			$b = json_decode((string)$body, true);
			foreach (array('message', 'first_name', 'last_name', 'email', 'orcid', 'institution', 'position_role') as $req) {
				if (!is_array($b) || !isset($b[$req]) || $b[$req] === '') return array(400, json_encode(array($req => array('This field is required.'))), 0);
			}
			$s['access_requests'][] = $b;
			return array(200, '', 0);
		}

		// -- anonymous: landing-page lookup ---------------------------------------
		if ($method === 'GET' && $path === 'samples/by-igsn/') {
			$igsn = isset($query['igsn']) ? (string)$query['igsn'] : '';
			if (!isset($s['samples'][$igsn])) return array(404, json_encode(array('error' => 'Sample does not exist')), 0);
			$rec = $s['samples'][$igsn];
			if (!empty($rec['_deactivated'])) return array(410, json_encode(array('igsn' => $igsn, 'name' => $rec['name'], 'archive_date' => '2026-01-01')), 0);
			if (!empty($rec['_private'])) return self::err(403, 'detail', 'This sample is private.');
			return array(200, json_encode(array('data' => self::publicView($rec))), 0);
		}

		// -- everything else needs a valid access token ------------------------
		$bearer = preg_replace('/^Bearer\s+/', '', (string)self::header($headers, 'Authorization'));
		$tok = ($bearer !== '' && isset($s['tokens'][$bearer])) ? $s['tokens'][$bearer] : null;
		$authed = $tok !== null && $tok['kind'] === 'access' && $tok['exp'] >= time();
		if ($bearer !== '' && !$authed) return array(401, json_encode(array('detail' => 'Given token not valid for any token type', 'code' => 'token_not_valid')), 0);

		if ($method === 'GET' && $path === 'auth/user/') {
			// Real shape (sandbox 09-26); jwt_connection comes back null despite the claim.
			return array(200, json_encode(array('data' => array('user' => array(
				'individual' => array('label' => 'User, Fake', 'fname' => 'Fake', 'lname' => 'User', 'individual_uri' => $tok['orcid']),
				'email' => 'fake+' . $tok['orcid'] . '@example.org', 'orcid' => $tok['orcid'], 'upload_permission_status' => 1),
				'jwt_connection' => null))), 0);
		}
		if ($method === 'GET' && $path === 'sesar-codes/by-permission/') {
			$mine = isset($s['codes'][$tok['orcid']]) ? $s['codes'][$tok['orcid']] : array();
			return array(200, json_encode(array_map(function ($c) use ($tok) {
				return array('sesar_user' => $tok['orcid'], 'team' => null, 'sesar_code' => $c, 'doi_prefix' => '10.58052/', 'igsn_count' => 0);
			}, $mine)), 0);
		}
		if ($method === 'POST' && $path === 'sesar-codes/') {
			if (!$authed) return self::err(401, 'detail', 'Authentication credentials were not provided.');
			$b = json_decode((string)$body, true);
			$code = is_array($b) && isset($b['sesar_code']) ? (string)$b['sesar_code'] : '';
			// Real SESAR answers these with a BARE field dict (no message/errors envelope).
			if (!preg_match('/^IE[A-Za-z0-9]{3}$/', $code)) {
				return array(400, json_encode(array('sesar_code' => array('SESAR code must be 5 characters long, alphanumeric, and begin with IE.'))), 0);
			}
			foreach ($s['codes'] as $o => $list) {
				if (in_array($code, $list, true)) return array(400, json_encode(array('sesar_code' => array('sesar code with this sesar code already exists.'))), 0);
			}
			$s['codes'][$tok['orcid']][] = $code;
			return array(201, json_encode(array('sesar_code' => $code, 'sesar_user' => $tok['orcid'], 'team' => null)), 0);
		}
		if ($method === 'POST' && $path === 'related-resources/') {
			if (!$authed) return self::err(401, 'detail', 'Authentication credentials were not provided.');
			$b = json_decode((string)$body, true);
			foreach (array('label', 'uri', 'related_resource_type') as $req) {
				if (empty($b[$req])) return self::err(400, $req, 'This field is required.');
			}
			foreach ($s['related'] as $rr) {
				if ($rr['uri'] === $b['uri']) return array(400, json_encode(array('message' => 'A resource with this filename/URI already exists.',
					'errors' => array('uri' => array('A resource with this filename/URI already exists.')))), 0);
			}
			$s['rr_seq'] = (isset($s['rr_seq']) ? $s['rr_seq'] : 1208000) + 1;
			$s['related'][(string)$s['rr_seq']] = $b + array('_owner' => $tok['orcid']);
			return array(201, json_encode(array('data' => array('id' => $s['rr_seq']) + $b)), 0);
		}
		if ($method === 'POST' && preg_match('#^related-resources/([0-9]+)/link-samples/$#', $path, $m)) {
			if (!$authed) return self::err(401, 'detail', 'Authentication credentials were not provided.');
			if (!isset($s['related'][$m[1]])) return self::err(404, 'detail', 'Related resource not found.');
			if ($s['related'][$m[1]]['_owner'] !== $tok['orcid']) return self::err(403, 'detail', 'Insufficient permission on this resource.');
			$b = json_decode((string)$body, true);
			if (empty($b['sample_ids'])) return self::err(400, 'sample_ids', 'No samples to process.');
			foreach ($b['sample_ids'] as $sid) $s['related'][$m[1]]['_samples'][] = (int)$sid;
			return array(200, json_encode(array('data' => array('processed' => count($b['sample_ids'])))), 0);
		}
		if ($method === 'GET' && $path === 'related-resources/') {
			$rows = array();
			foreach ($s['related'] as $id => $rr) {
				if ($rr['_owner'] !== $tok['orcid']) continue;
				if (isset($query['search']) && stripos($rr['label'], $query['search']) === false) continue;   // labels only, like SESAR
				$rows[] = array('id' => (int)$id, 'label' => $rr['label'], 'uri' => $rr['uri']);
			}
			return array(200, json_encode(array('count' => count($rows), 'next' => null, 'data' => $rows)), 0);
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
			if (!empty($p['general_material_type']) && in_array($p['general_material_type'], array('Rock', 'Igneous rock'), true)) {
				return self::err(400, 'general_material_type', "Material type '" . $p['general_material_type'] . "' is not available for registration.");
			}
			foreach ((array)(isset($p['collectors']) ? $p['collectors'] : array()) as $c) {
				// Real sandbox 09-27: a bare label SESAR knows more than once is refused.
				if (isset($c['individual']['label']) && $c['individual']['label'] === 'Common, Name' && empty($c['individual']['individual_uri'])) {
					return self::err(400, 'collectors', 'Ambiguous individual match by label.');
				}
			}
			foreach ((array)(isset($p['related_resources']) ? $p['related_resources'] : array()) as $rid) {
				if (!isset($s['related'][(string)$rid])) return self::err(400, 'related_resources', 'Invalid pk "' . $rid . '" - object does not exist.');
			}
			$s['seq']++;
			$s['sample_seq'] = (isset($s['sample_seq']) ? $s['sample_seq'] : 900000) + 1;
			$igsn = '10.58052/' . $p['sesar_code'] . str_pad((string)$s['seq'], 4, '0', STR_PAD_LEFT);
			$rec = self::toRecord($p, $igsn, $tok['orcid']);
			$rec['_sample_id'] = $s['sample_seq'];
			$s['samples'][$igsn] = $rec;
			if (!empty($s['knobs']['register_fail_after'])) {
				unset($s['knobs']['register_fail_after']);
				return array(502, '<html>502 Bad Gateway</html>', 0);   // registered, gateway error on the way back
			}
			if (!empty($s['knobs']['register_then_timeout'])) {
				unset($s['knobs']['register_then_timeout']);
				return array(0, '', 0);   // registered at SESAR, but the response is lost
			}
			return array(201, json_encode(self::publicView($rec)), 0);   // real POST answer: not wrapped, no sample_id
		}
		if (preg_match('#^samples/(10\.[0-9]+/[^/]+)/deactivate/$#', $path, $m) && $method === 'POST') {
			$igsn = urldecode($m[1]);
			if (!isset($s['samples'][$igsn])) return self::err(404, 'detail', 'Not found.');
			$b = json_decode((string)$body, true);
			if (empty($b['deactivate_reason'])) return self::err(400, 'deactivate_reason', 'This field is required.');
			if (!empty($s['samples'][$igsn]['deactivation_requested'])) {
				return array(400, json_encode(array('message' => 'A deactivation request already exists for this sample.',
					'errors' => array('non_field_errors' => array('A deactivation request already exists for this sample.')))), 0);
			}
			$s['samples'][$igsn]['deactivation_requested'] = $b;
			return array(200, json_encode(array('data' => array('igsn' => $igsn, 'status' => 'requested'))), 0);
		}
		if (preg_match('#^samples/(10\.[0-9]+/[^/]+)/$#', $path, $m)) {
			$igsn = urldecode($m[1]);
			if (!isset($s['samples'][$igsn])) return self::err(404, 'detail', 'Not found.');
			if (!empty($s['samples'][$igsn]['_deactivated'])) return self::err(410, 'detail', 'This sample has been deactivated.');
			if ($method === 'GET') {
				// Real detail (sandbox 09-27): can_edit / can_deactivate for the caller,
				// but NO last_update_date (nor sample_id / external_sample_id: only list rows carry those).
				$rec = $s['samples'][$igsn];
				$mine = $authed && $rec['_owner'] === $tok['orcid'];
				if (!empty($rec['_private']) && !$mine) return self::err(403, 'detail', 'You do not have permission to perform this action.');
				$out = self::publicView($rec);
				unset($out['last_update_date'], $out['external_sample_id']);
				$out['can_edit'] = $mine;
				$out['can_deactivate'] = $mine && empty($rec['deactivation_requested']);
				return array(200, json_encode(array('data' => $out)), 0);
			}
			if ($method === 'PATCH') {
				if (!$authed || $s['samples'][$igsn]['_owner'] !== $tok['orcid']) return self::err(403, 'detail', 'You do not have permission to perform this action.');
				foreach (json_decode((string)$body, true) as $k => $v) {
					if (in_array($k, array('igsn', 'sesar_code'), true)) return self::err(400, $k, 'Cannot be changed.');
					$s['samples'][$igsn][$k] = self::outValue($k, $v);
				}
				$s['samples'][$igsn]['last_update_date'] = gmdate('Y-m-d\TH:i:s\Z');
				return array(200, json_encode(array('data' => self::publicView($s['samples'][$igsn]))), 0);
			}
		}
		if ($method === 'GET' && $path === 'samples/') {
			$rows = array();
			foreach ($s['samples'] as $igsn => $rec) {
				if (isset($query['external_sample_id']) && (string)$rec['external_sample_id'] !== (string)$query['external_sample_id']) continue;
				if (isset($query['igsn'])) {
					$want = strtoupper((string)$query['igsn']);
					if (strpos($want, '/') === false) $want = '10.58052/' . $want;
					if (strtoupper($igsn) !== $want) continue;
				}
				if (isset($query['search']) && stripos($igsn . ' ' . (isset($rec['name']) ? $rec['name'] : ''), (string)$query['search']) === false) continue;
				if ($authed && $rec['_owner'] !== $tok['orcid']) continue;   // scope=personal
				if (!empty($rec['_deactivated'])) continue;
				if (!$authed && !empty($rec['_private'])) continue;
				$rows[] = self::publicView($rec) + array('sample_id' => isset($rec['_sample_id']) ? $rec['_sample_id'] : null);
			}
			if (!empty($s['knobs']['list_timeout'])) return array(504, '<html>504 Gateway Time-out</html>', 0);
			$size = isset($query['page_size']) ? max(1, (int)$query['page_size']) : 100;
			$page = isset($query['page']) ? max(1, (int)$query['page']) : 1;
			$total = count($rows);
			$rows = array_slice($rows, ($page - 1) * $size, $size);
			return array(200, json_encode(array('count' => $total, 'next' => ($page * $size < $total) ? 'next' : null, 'previous' => null, 'data' => $rows,
				'page_size' => $size, 'current_page' => $page, 'total_pages' => max(1, (int)ceil($total / $size)))), 0);
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
		foreach (array('orcid_users', 'tokens', 'samples', 'knobs', 'related') as $k) if (!isset($s[$k]) || !is_array($s[$k])) $s[$k] = array();
		$fn($s);
		foreach (array('orcid_users', 'tokens', 'samples', 'knobs', 'related') as $k) if (empty($s[$k])) $s[$k] = new stdClass();
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

	/** What SESAR shows: no internal keys, no sample_id (only list rows carry it). */
	private static function publicView(array $rec)
	{
		$out = array();
		foreach ($rec as $k => $v) {
			if ($k === '' || $k[0] !== '_') $out[$k] = $v;
		}
		return $out;
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
