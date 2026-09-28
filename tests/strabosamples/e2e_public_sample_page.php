<?php
/**
 * File: tests/strabosamples/e2e_public_sample_page.php
 * Description: The public read-only Sample Overview (/samples/{owner}/{id}
 *              for logged-out visitors, 2026-09-27) over real HTTP:
 *              - samples_public_many / _status rule (samplesdb/lib/sample_public.php):
 *                public via a public Field, Micro or Exp host project, or
 *                via an IGSN StraboSpot registered at SESAR;
 *              - anonymous: private and missing samples answer the same 404
 *                "not found or not public"; public ones get a trimmed payload
 *                (notes / custom fields only via a public project, cards only
 *                for public hosts, relatives only when public, never
 *                collaborators, no owner permissions);
 *              - logged in: unchanged (private sample, every card, collaborators).
 *
 *              Fixtures: users 94740-94742, samples "pubpage-*", fake spots /
 *              Micro + Exp projects keyed "pubpage-*". Everything is removed
 *              at the end.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_public_sample_page.php
 *
 * @package    StraboSpot Tests
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'samplesdb/lib/sample_public.php';

$OWNER = 94740; $OTHER = 94741; $COLLAB = 94742;
$U = array($OWNER, $OTHER, $COLLAB);
$sessionFiles = array();
$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 400) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }

function cleanup() {
	global $db, $U, $sessionFiles;
	$in = implode(',', array_map('intval', $U));
	$db->query("DELETE FROM strabosearch.item_hit WHERE item_type = 'spot' AND item_id LIKE 'pubpage-%'");
	$db->query("DELETE FROM micro_permalinks WHERE strabo_id LIKE 'pubpage-%'");
	$db->query("DELETE FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE 'pubpage-%'");
	$db->query("DELETE FROM straboexp.experiment WHERE uuid LIKE 'pubpage-%'");
	$db->query("DELETE FROM straboexp.project WHERE uuid LIKE 'pubpage-%'");
	if (samples_public_has_sesar_table($db)) $db->query("DELETE FROM strabosamples.sesar_registrations WHERE sample_userpkey IN ($in)");
	$db->query("UPDATE strabosamples.samples SET parent_sample_id = NULL, parent_userpkey = NULL WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey IN ($in)");
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
	foreach ($sessionFiles as $f) @unlink($f);
}

function mk($id, array $f = array()) {
	global $db, $OWNER;
	$db->prepare_query(
		"INSERT INTO strabosamples.samples (id, userpkey, name, description, notes, latitude, longitude, custom_data,
		                                   parent_sample_id, parent_userpkey, created_by, modified_by)
		 VALUES ($1, $2, $3, 'Public page fixture', $4, 38.95, -95.25, $5::jsonb, $6, $7, $2, $2)",
		array($id, $OWNER, 'Name ' . $id, 'secret notes ' . $id, json_encode(array('lab_code' => 'LC-' . $id)),
		      isset($f['parent']) ? $f['parent'] : null, isset($f['parent']) ? $OWNER : null));
}
function link_field($id, $spot, $public) {
	global $db, $OWNER;
	$db->prepare_query("INSERT INTO strabosamples.sample_subsystem_links (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
		VALUES ($1, $2, 'field', $3, $2, $4::jsonb)", array($id, $OWNER, $spot, json_encode(array('dataset_id' => '1', 'project_name' => 'Field proj ' . $spot))));
	$db->prepare_query("INSERT INTO strabosearch.item_hit (item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem, project_ispublic)
		VALUES ('spot', $1, $2, 'pubpage-proj', $2, 'field', $3)", array($spot, $OWNER, $public ? 't' : 'f'));
}
function link_micro($id, $sid, $public) {
	global $db, $OWNER;
	$db->prepare_query("INSERT INTO strabomicro.micro_projectmetadata (strabo_id, userpkey, name, ispublic) VALUES ($1, $2, $3, $4)",
		array($sid, $OWNER, 'Micro ' . $sid, $public ? 't' : 'f'));
	$db->prepare_query("INSERT INTO strabosamples.sample_subsystem_links (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
		VALUES ($1, $2, 'micro', '1', $2, $3::jsonb)", array($id, $OWNER, json_encode(array('project_strabo_id' => $sid, 'project_name' => 'Micro proj ' . $sid))));
}
function link_exp($id, $uuid, $public) {
	global $db, $OWNER;
	$db->prepare_query("INSERT INTO straboexp.project (userpkey, uuid, name, ispublic) VALUES ($1, $2, $3, $4)",
		array($OWNER, $uuid, 'Exp ' . $uuid, $public ? 't' : 'f'));
	$ppk = $db->get_var_prepared("SELECT pkey FROM straboexp.project WHERE uuid = $1", array($uuid));
	$db->prepare_query("INSERT INTO straboexp.experiment (project_pkey, userpkey, uuid) VALUES ($1, $2, $3)", array((int)$ppk, $OWNER, $uuid . '-x'));
	$db->prepare_query("INSERT INTO strabosamples.sample_subsystem_links (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
		VALUES ($1, $2, 'experimental', $3, $2, $4::jsonb)", array($id, $OWNER, $uuid, json_encode(array('project_uuid' => $uuid, 'experiment_uuid' => $uuid . '-x'))));
}

function forgeSession($pkey) {
	global $sessionFiles;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = '/var/lib/php/sessions/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600); @chown($path, 'www-data'); @chgrp($path, 'www-data');
	$sessionFiles[] = $path;
	return $sid;
}
/** GET the page; returns status, body and the decoded sd-data payload (or null). */
function page($id, $sid = null, $owner = null) {
	global $OWNER;
	$ch = curl_init('http://localhost/samples/' . ($owner === null ? $OWNER : $owner) . '/' . rawurlencode($id));
	$o = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false);
	if ($sid !== null) $o[CURLOPT_HTTPHEADER] = array('Cookie: PHPSESSID=' . $sid);
	curl_setopt_array($ch, $o);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$payload = preg_match('#<script type="application/json" id="sd-data">(.*?)</script>#s', $body, $m) ? json_decode($m[1], true) : null;
	return array('status' => $code, 'body' => $body, 'p' => $payload);
}
function subsystems($p) { return array_map(function ($l) { return $l['subsystem']; }, $p['links']); }

cleanup();
foreach (array($OWNER => 'Owner', $OTHER => 'Other', $COLLAB => 'Collab') as $u => $n) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, 'Pub', $2, $3, 'x', 'x', TRUE, FALSE)",
		array($u, $n, "pubpage$u@test.strabospot.org"));
}

try {

// ---- fixtures -------------------------------------------------------------
mk('pubpage-field-pub');        link_field('pubpage-field-pub', 'pubpage-spot-1', true);
mk('pubpage-field-priv');       link_field('pubpage-field-priv', 'pubpage-spot-2', false);
mk('pubpage-micro-pub');        link_micro('pubpage-micro-pub', 'pubpage-m1', true);
mk('pubpage-exp-pub');          link_exp('pubpage-exp-pub', 'pubpage-e1', true);
mk('pubpage-exp-priv');         link_exp('pubpage-exp-priv', 'pubpage-e2', false);
mk('pubpage-mixed');            link_field('pubpage-mixed', 'pubpage-spot-3', true); link_micro('pubpage-mixed', 'pubpage-m2', false);
                                link_exp('pubpage-mixed', 'pubpage-e3', false);
mk('pubpage-unlinked');
mk('pubpage-kid-pub', array('parent' => 'pubpage-mixed'));   link_field('pubpage-kid-pub', 'pubpage-spot-4', true);
mk('pubpage-kid-priv', array('parent' => 'pubpage-mixed'));
mk('pubpage-kid-of-priv', array('parent' => 'pubpage-unlinked')); link_field('pubpage-kid-of-priv', 'pubpage-spot-5', true);
$db->prepare_query("INSERT INTO strabosamples.sample_collaborators (sample_id, sample_userpkey, collaborator_pkey, permission_level, uuid, accepted, accepted_at, added_by)
	VALUES ('pubpage-mixed', $1, $2, 'edit', 'pubpage-collab-1', TRUE, now(), $1)", array($OWNER, $COLLAB));
$hasSesar = samples_public_has_sesar_table($db);
if ($hasSesar) {
	mk('pubpage-igsn');
	$db->prepare_query("UPDATE strabosamples.samples SET igsn = '10.58052/IEXXX0001' WHERE id = 'pubpage-igsn' AND userpkey = $1", array($OWNER));
	$db->prepare_query("INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, origin, state, created_by)
		VALUES ('pubpage-igsn', $1, 'production', '10.58052/IEXXX0001', 'minted', 'active', $1)", array($OWNER));
	mk('pubpage-igsn-gone');
	$db->prepare_query("INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, origin, state, active, created_by)
		VALUES ('pubpage-igsn-gone', $1, 'production', '10.58052/IEXXX0002', 'minted', 'deactivated', FALSE, $1)", array($OWNER));
	// Only production mints count: sandbox and pulled / batch-linked rows stay private.
	mk('pubpage-igsn-asked');
	$db->prepare_query("INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, origin, state, created_by)
		VALUES ('pubpage-igsn-asked', $1, 'production', '10.58052/IEXXX0003', 'minted', 'deactivation_requested', $1)", array($OWNER));
	mk('pubpage-igsn-sandbox');
	$db->prepare_query("INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, origin, state, created_by)
		VALUES ('pubpage-igsn-sandbox', $1, 'sandbox', '10.58052/IEXXX0004', 'minted', 'active', $1)", array($OWNER));
	mk('pubpage-igsn-pulled');
	$db->prepare_query("INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, origin, access, state, created_by)
		VALUES ('pubpage-igsn-pulled', $1, 'production', '10.58052/IEXXX0005', 'linked', 'managed', 'active', $1)", array($OWNER));
	mk('pubpage-igsn-theirs');
	$db->prepare_query("INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, origin, access, state, created_by)
		VALUES ('pubpage-igsn-theirs', $1, 'production', '10.58052/IEXXX0006', 'linked', 'readonly', 'active', $1)", array($OWNER));
}

// ===========================================================================
section('rule: samples_public_many');
$ids = array('pubpage-field-pub', 'pubpage-field-priv', 'pubpage-micro-pub', 'pubpage-exp-pub', 'pubpage-exp-priv', 'pubpage-mixed', 'pubpage-unlinked');
$m = samples_public_many($db, array_map(function ($i) use ($OWNER) { return array($i, $OWNER); }, $ids));
$is = function ($id) use ($m, $OWNER) { return isset($m[$id . '|' . $OWNER]); };
check('public Field project -> public (via project)', $is('pubpage-field-pub') && $m['pubpage-field-pub|' . $OWNER]['via_project']);
check('private Field project -> not public', !$is('pubpage-field-priv'));
check('public Micro project -> public', $is('pubpage-micro-pub'));
check('public Exp project -> public', $is('pubpage-exp-pub'));
check('private Exp project -> not public', !$is('pubpage-exp-priv'));
check('one public host among private ones -> public', $is('pubpage-mixed'));
check('no links, no IGSN -> not public', !$is('pubpage-unlinked'));
check("same id under another owner -> not public (owner is part of the key)", samples_public_status($db, 'pubpage-field-pub', $OTHER) === null);
if ($hasSesar) {
	$st = samples_public_status($db, 'pubpage-igsn', $OWNER);
	check('registered IGSN -> public via IGSN only', $st !== null && $st['via_igsn'] && !$st['via_project'], $st);
	check('deactivated registration -> not public', samples_public_status($db, 'pubpage-igsn-gone', $OWNER) === null);
	$st = samples_public_status($db, 'pubpage-igsn-asked', $OWNER);
	check('deactivation requested (still public at SESAR) -> public via IGSN', $st !== null && $st['via_igsn']);
	check('sandbox IGSN -> not public', samples_public_status($db, 'pubpage-igsn-sandbox', $OWNER) === null);
	check('pulled IGSN (linked, managed) -> not public', samples_public_status($db, 'pubpage-igsn-pulled', $OWNER) === null);
	check("a colleague's IGSN (linked, read-only) -> not public", samples_public_status($db, 'pubpage-igsn-theirs', $OWNER) === null);
}

// ===========================================================================
section('anonymous: refusals');
$r = page('pubpage-field-priv');
check('private sample -> 404 "not found or not public", no payload, login link', $r['status'] === 404 && $r['p'] === null
	&& strpos($r['body'], 'not found or not public') !== false && strpos($r['body'], 'href="/login.php"') !== false);
$r2 = page('pubpage-does-not-exist');
check('missing sample -> the identical answer (no probing)', $r2['status'] === 404 && strpos($r2['body'], 'not found or not public') !== false
	&& strpos($r['body'], 'pubpage-field-priv') === false && strpos($r['body'], 'secret notes') === false);
check('no PHP warnings on the refusal', !preg_match('/(Warning|Notice|Fatal error)/', $r['body']));

// ===========================================================================
section('anonymous: public via project');
$r = page('pubpage-mixed');
$p = $r['p'];
check('200 with payload, anonymous flag, no permissions', $r['status'] === 200 && $p !== null && $p['anonymous'] === true
	&& $p['permissions']['isOwner'] === false && $p['permissions']['canEdit'] === false, $r['status']);
check('metadata incl. notes + custom fields (public project: already searchable)', $p['sample']['name'] === 'Name pubpage-mixed'
	&& $p['sample']['notes'] === 'secret notes pubpage-mixed' && $p['sample']['custom_data']['lab_code'] === 'LC-pubpage-mixed');
check('owner name shown', $p['owner']['name'] === 'Pub Owner');
check('only the public host card; private Micro/Exp cards (and their names) gone', subsystems($p) === array('field')
	&& strpos($r['body'], 'Micro proj pubpage-m2') === false && strpos($r['body'], 'pubpage-e3') === false, subsystems($p));
check('no collaborators (even accepted ones)', $p['collaborators'] === array() && strpos($r['body'], 'Pub Collab') === false);
$kids = array_map(function ($c) { return $c['id']; }, $p['family']['children']);
check('family: public child kept, private child dropped', $kids === array('pubpage-kid-pub'), $kids);
check('owner-only controls hidden (sd-anon)', strpos($r['body'], 'sd-wrap sd-anon') !== false);
check('no PHP warnings', !preg_match('/(Warning|Notice|Fatal error)/', $r['body']));
$r = page('pubpage-kid-of-priv');
check('private parent never shown to anonymous', $r['p']['family']['parent'] === null && strpos($r['body'], 'Name pubpage-unlinked') === false);
$r = page('pubpage-micro-pub');
check('public Micro card links to the project', subsystems($r['p']) === array('micro') && !empty($r['p']['links'][0]['view_href']));
$r = page('pubpage-exp-pub');
check('public Exp card shown', subsystems($r['p']) === array('experimental'));

if ($hasSesar) {
	section('anonymous: public via IGSN only');
	$r = page('pubpage-igsn');
	$p = $r['p'];
	check('shown: name, IGSN, location, description', $r['status'] === 200 && $p['sample']['igsn'] === '10.58052/IEXXX0001'
		&& $p['sample']['latitude'] === 38.95 && $p['sample']['description'] === 'Public page fixture');
	check('hidden: notes, custom fields, subsystem blobs (SESAR never had them)', $p['sample']['notes'] === null && $p['sample']['custom_data'] === null
		&& $p['sample']['field_data'] === null && strpos($r['body'], 'secret notes') === false && strpos($r['body'], 'LC-pubpage-igsn') === false);
	foreach (array('pubpage-igsn-sandbox' => 'sandbox IGSN', 'pubpage-igsn-pulled' => 'pulled IGSN', 'pubpage-igsn-theirs' => "colleague's IGSN") as $id => $what) {
		$r = page($id);
		check("$what: the page answers 404 to a logged-out visitor", $r['status'] === 404 && strpos($r['body'], 'Public page fixture') === false, $r['status']);
	}
}

// ===========================================================================
section('logged in: unchanged');
$other = forgeSession($OTHER);
$r = page('pubpage-field-priv', $other);
check('any logged-in user still opens a private sample by URL (share link rule)', $r['status'] === 200 && $r['p']['anonymous'] === false
	&& $r['p']['sample']['notes'] === 'secret notes pubpage-field-priv');
$r = page('pubpage-mixed', $other);
check('logged in: every card (private ones disabled) + collaborators', count($r['p']['links']) === 3 && count($r['p']['collaborators']) === 1
	&& strpos($r['body'], 'sd-wrap sd-anon') === false);
$owner = forgeSession($OWNER);
$r = page('pubpage-mixed', $owner);
check('owner: permissions intact', $r['p']['permissions']['isOwner'] === true && $r['p']['permissions']['canEdit'] === true);
$r = page('pubpage-does-not-exist', $other);
check('logged in, missing: the old "Sample not found." (200)', $r['status'] === 200 && strpos($r['body'], 'Sample not found.') !== false);

} finally {
	cleanup();
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
