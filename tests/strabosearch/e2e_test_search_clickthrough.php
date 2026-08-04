<?php
/**
 * File: e2e_test_search_clickthrough.php
 * Description: Phase 5.B E2E — the full user workflow over real HTTP:
 *              search (session proxy, anonymous + owner) → results →
 *              click-through to the landing pages the UI links
 *              (/fpl/ /mpl/ /epl/ per results.js CFG.landing), for all
 *              four subsystems + the images pathway.
 *
 *              What the /searchdb/ + UI suites can't see: the landing
 *              pages resolve from OTHER stores (Field = the PG mirror
 *              project/dataset/spot tables; Micro/Exp = their native PG),
 *              so an index hit can 404 on click-through — this suite
 *              pins the seam. Also pins the landing-page ACL posture:
 *              (ispublic OR owner) on all three, incl. the exp landing
 *              gate added in Phase 5 (it had NO ACL check before —
 *              private experiment names + download links were served to
 *              anyone with the uuid).
 *
 *              KNOWN GAP (documented, not asserted): collaborators can
 *              see a shared project in search results but the landing
 *              pages gate on (ispublic OR owner) only — same accepted
 *              posture as Micro/Exp detail pages.
 *
 *              Hermetic: fixture user upk 94570 ('spse2e'). Zero residue.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/e2e_test_search_clickthrough.php
 *
 * @package    StraboSearch Phase 5
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$UPK  = 94570;
$PFX  = 'spse2e';
$BASE = 'http://localhost';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . "== $t" . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

// ---------------------------------------------------------------------------
// Forged session + HTTP helpers (same harness as smoke_test_search_ui)
// ---------------------------------------------------------------------------
$sessionDir   = '/var/lib/php/sessions';
$sessionFiles = array();

function forgeSession($pkey) {
	global $sessionDir, $sessionFiles;
	$sid  = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = $sessionDir . '/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600);
	@chown($path, 'www-data');
	@chgrp($path, 'www-data');
	$sessionFiles[] = $path;
	return $sid;
}

/** Returns array(status, headers-string, body). No redirect following —
 *  the micro/exp landings answer with a 302 we assert on. */
function http_raw($method, $url, $sid = null, $jsonBody = null) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	$headers = array();
	if ($sid !== null) $headers[] = 'Cookie: PHPSESSID=' . $sid;
	if ($jsonBody !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
		$headers[] = 'Content-Type: application/json';
	}
	if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	$raw = (string)curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	return array($status, substr($raw, 0, $hsize), substr($raw, $hsize));
}

function searchProxy($sid, $dsl) {
	global $BASE;
	list($status, $hdrs, $raw) = http_raw('POST',
		$BASE . '/strabosearch/api.php?action=search', $sid, $dsl);
	return array($status, json_decode($raw, true));
}

function locationHeader($hdrs) {
	foreach (explode("\n", $hdrs) as $line) {
		if (stripos($line, 'Location:') === 0) return trim(substr($line, 9));
	}
	return '';
}

/** Anonymous keyword search → the single expected project result. */
function findProject($keyword, $subsystems = null) {
	$dsl = array('pathway' => 'projects', 'criteria' => array(
		array('id' => 'U1', 'value' => $keyword)));
	if ($subsystems !== null) $dsl['subsystems'] = $subsystems;
	list($st, $j) = searchProxy(null, $dsl);
	if ($st !== 200 || !$j || !isset($j['results'][0])) return array($st, $j, null);
	return array($st, $j, $j['results'][0]);
}

/** results.js CFG.landing click-through URL for a project result row. */
function landingUrl($r) {
	$map = array('field' => '/fpl/', 'micro' => '/mpl/', 'exp' => '/epl/');
	$base = isset($map[$r['project_subsystem']]) ? $map[$r['project_subsystem']] : '/fpl/';
	return $base . rawurlencode($r['project_id']);
}

// ---------------------------------------------------------------------------
section('0. Fixtures');

function e2e_neo_cleanup($neodb, $UPK) {
	// All Neo4j fixture nodes carry the fixture userpkey (full scan, same
	// cost class as the extractor suite's id-regex cleanup — dev-only).
	$neodb->query("MATCH (n) WHERE n.userpkey = $UPK DETACH DELETE n");
}

function e2e_cleanup($db, $UPK, $PFX) {
	$db->prepare_query("DELETE FROM strabosearch.item_hit  WHERE project_id LIKE $1", array($PFX . '_%'));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_id LIKE $1", array($PFX . '_%'));
	$db->prepare_query("DELETE FROM spot    WHERE user_pkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM dataset WHERE user_pkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM project WHERE user_pkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE userpkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM straboexp.experiment WHERE userpkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM straboexp.project WHERE userpkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($UPK));
}
e2e_cleanup($db, $UPK, $PFX);
e2e_neo_cleanup($neodb, $UPK);

$db->prepare_query(
	"INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted)
	 VALUES ($1, 'Ed', 'Endtoend', $2, 'x', 'x', TRUE, FALSE)",
	array($UPK, $PFX . '@example.com'));

// -- FIELD: PG-mirror chain (what /fpl/ reads) + index rows, public + private
foreach (array(
	array('proj' => $PFX . '_fp_pub',  'pub' => 'TRUE',  'key' => 'UNIQE2E_fpub'),
	array('proj' => $PFX . '_fp_priv', 'pub' => 'FALSE', 'key' => 'UNIQE2E_fpriv'),
) as $f) {
	$db->query("INSERT INTO project (strabo_project_id, user_pkey, ispublic, project_name)
		VALUES ('{$f['proj']}', $UPK, {$f['pub']}, 'spse2e field {$f['proj']}')");
	$ppk = (int)$db->insert_id;
	$db->query("INSERT INTO dataset (project_pkey, user_pkey, strabo_dataset_id, dataset_name)
		VALUES ($ppk, $UPK, '{$f['proj']}_ds', 'spse2e Field DS {$f['proj']}')");
	$dpk = (int)$db->insert_id;
	$db->query("INSERT INTO spot (dataset_pkey, project_pkey, user_pkey, strabo_spot_id, date_created)
		VALUES ($dpk, $ppk, $UPK, '{$f['proj']}_s1', now())");
	if ($f['pub'] === 'TRUE') {
		// Second dataset on the public project: /fpl/ 302s single-dataset
		// projects straight into /fieldland/ — two datasets exercise the
		// dataset-list page instead.
		$db->query("INSERT INTO dataset (project_pkey, user_pkey, strabo_dataset_id, dataset_name)
			VALUES ($ppk, $UPK, '{$f['proj']}_ds2', 'spse2e Field DS2 {$f['proj']}')");
	}
	// dataset_ids mirrors the PG-mirror datasets: public project = 2 (list
	// page + /fpl/ chooser path), private = 1 (deep-link + redirect path).
	$dsArr = ($f['pub'] === 'TRUE')
		? "{{$f['proj']}_ds,{$f['proj']}_ds2}" : "{{$f['proj']}_ds}";
	$db->query("INSERT INTO strabosearch.item_hit
		(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
		 project_name, project_ispublic, searchtext_tsv, dataset_ids, source_modified)
		VALUES ('spot', '{$f['proj']}_s1', $UPK, '{$f['proj']}', $UPK, 'field',
		 'spse2e field {$f['proj']}', {$f['pub']}, to_tsvector('english', '{$f['key']}'),
		 '$dsArr', now())");
}

// samples fan-out row hosted on the public field project (U8=samples path)
$db->query("INSERT INTO strabosearch.item_hit
	(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
	 project_name, project_ispublic, sample_id, sample_name, searchtext_tsv, source_modified)
	VALUES ('sample', '{$PFX}_samp1', $UPK, '{$PFX}_fp_pub', $UPK, 'field',
	 'spse2e field {$PFX}_fp_pub', TRUE, '{$PFX}_samp1', 'spse2e sample',
	 to_tsvector('english', 'UNIQE2E_spub'), now())");

// images-pathway row on the public field project
$db->query("INSERT INTO strabosearch.image_hit
	(image_id, image_subsystem, image_userpkey, image_type, title, filename,
	 project_id, project_userpkey, project_subsystem, project_ispublic,
	 imagetext_tsv, source_modified)
	VALUES ('{$PFX}_img1', 'field', $UPK, 'photo', 'spse2e image', '{$PFX}_nofile',
	 '{$PFX}_fp_pub', $UPK, 'field', TRUE,
	 to_tsvector('english', 'UNIQE2E_ipub'), now())");

// -- MICRO: native metadata row (what /mpl/ reads) + index row
$db->query("INSERT INTO strabomicro.micro_projectmetadata
	(strabo_id, userpkey, name, ispublic, modifiedtimestamp, notes)
	VALUES ('{$PFX}_mp_pub', $UPK, 'spse2e micro project', TRUE, '1722400005000', '')");
$mpid = (int)$db->insert_id;
$db->query("INSERT INTO strabosearch.item_hit
	(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
	 project_name, project_ispublic, searchtext_tsv, source_modified)
	VALUES ('micrograph', '{$PFX}_mg1', $UPK, '{$PFX}_mp_pub', $UPK, 'micro',
	 'spse2e micro project', TRUE, to_tsvector('english', 'UNIQE2E_mpub'), now())");

// -- EXP: native project + experiment (what /epl/ reads) + index rows,
//    public + private (the private one pins the Phase 5 landing gate)
foreach (array(
	array('uuid' => $PFX . '-exp-pub',  'pub' => 'TRUE',  'key' => 'UNIQE2E_epub'),
	array('uuid' => $PFX . '-exp-priv', 'pub' => 'FALSE', 'key' => 'UNIQE2E_epriv'),
) as $e) {
	$db->query("INSERT INTO straboexp.project (userpkey, uuid, name, notes, ispublic)
		VALUES ($UPK, '{$e['uuid']}', 'spse2e exp {$e['uuid']}', 'PRIVMARKER_expname', {$e['pub']})");
	$eppk = (int)$db->insert_id;
	$db->query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, json, modified_timestamp)
		VALUES ($eppk, $UPK, 'PRIVMARKER_expname_{$e['uuid']}', '{}', now())");
	$db->query("INSERT INTO strabosearch.item_hit
		(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
		 project_name, project_ispublic, searchtext_tsv, source_modified)
		VALUES ('experiment', 'PRIVMARKER_expname_{$e['uuid']}', $UPK, '{$e['uuid']}', $UPK, 'exp',
		 'spse2e exp {$e['uuid']}', {$e['pub']}, to_tsvector('english', '{$e['key']}'), now())");
}

// -- FIELD detail page (Neo4j): dataset + spots + project-level json_tags.
//    StraboFieldDatasetDetail/api/spots.php reads Neo4j (not the PG mirror)
//    and (int)-casts dataset_id, so this fixture needs numeric ids.
$NEO_DS     = 94570001;
$NEO_SPOT_A = 94570002;
$NEO_SPOT_B = 94570003;
$NEO_SPOT_C = 94570004;

$neodb->query("CREATE (p:Project {id: '{$PFX}_np', userpkey: $UPK,
		desc_project_name: 'spse2e neo project'})
	CREATE (d:Dataset {id: $NEO_DS, userpkey: $UPK, name: 'spse2e Neo DS'})
	CREATE (p)-[:HAS_DATASET]->(d)
	CREATE (sa:Spot {id: $NEO_SPOT_A, userpkey: $UPK, name: 'spse2e neo spot A',
		wkt: 'POINT(-97.66 38.58)', origwkt: 'POINT(-97.66 38.58)',
		gtype: 'Point', date: '2024-09-13T20:09:06.000Z', modified_timestamp: 1726258146})
	CREATE (d)-[:HAS_SPOT]->(sa)
	CREATE (sb:Spot {id: $NEO_SPOT_B, userpkey: $UPK, name: 'spse2e neo spot B',
		wkt: 'POINT(-97.67 38.59)', origwkt: 'POINT(-97.67 38.59)',
		gtype: 'Point', date: '2024-09-13T20:10:06.000Z', modified_timestamp: 1726258206})
	CREATE (d)-[:HAS_SPOT]->(sb)
	CREATE (sc:Spot {id: $NEO_SPOT_C, userpkey: $UPK, name: 'spse2e neo spot C',
		wkt: 'POINT(-97.68 38.60)', origwkt: 'POINT(-97.68 38.60)',
		gtype: 'Point', date: '2024-09-13T20:11:06.000Z', modified_timestamp: 1726258266})
	CREATE (d)-[:HAS_SPOT]->(sc)");

// Tag membership: geologic_unit on spot A (spots[]), a plain concept tag on
// A + B (spots[]), and a features-map-only tag on spot B (sub-spot tagging
// still surfaces on the spot's card). Spot C stays untagged.
$jtNeo = json_encode(array(
	array('id' => 94570090, 'type' => 'geologic_unit', 'name' => 'spse2e Alkali Unit',
	      'unit_label_abbreviation' => 'Kag', 'rock_type' => 'igneous',
	      'igneous_rock_class' => 'plutonic', 'plutonic_rock_types' => 'alkali_granite',
	      'spots' => array($NEO_SPOT_A)),
	array('id' => 94570091, 'type' => 'concept', 'name' => 'spse2e Concept Tag',
	      'spots' => array($NEO_SPOT_A, $NEO_SPOT_B)),
	array('id' => 94570092, 'type' => 'documentation', 'name' => 'spse2e Feature Tag',
	      'features' => array((string)$NEO_SPOT_B => array(94570099))),
));
$neodb->query("MATCH (p:Project {id: '{$PFX}_np'}) SET p.json_tags = '$jtNeo'");

$sid = forgeSession($UPK);
check('fixtures seeded', true);

// ---------------------------------------------------------------------------
section('1. FIELD — search → result → /fpl/ click-through');

list($st, $j, $r) = findProject('UNIQE2E_fpub');
check('anon search finds public field project', $r !== null
	&& $r['project_id'] === $PFX . '_fp_pub', 'total=' . ($j ? $j['total'] : "st=$st"));
check('result subsystem drives /fpl/ landing', $r !== null && landingUrl($r) === '/fpl/' . $PFX . '_fp_pub');

check('field result carries dataset_ids (drives the UI deep-link)',
	isset($r['dataset_ids']) && $r['dataset_ids'] === array($PFX . '_fp_pub_ds', $PFX . '_fp_pub_ds2'),
	json_encode(isset($r['dataset_ids']) ? $r['dataset_ids'] : null));

list($st, $h, $body) = http_raw('GET', $BASE . landingUrl($r), null);
check('anon /fpl/ click-through 200', $st === 200, "got $st");
check('landing shows the dataset list', strpos($body, 'spse2e Field DS') !== false);
check('landing links into StraboFieldDatasetDetail',
	strpos($body, '/StraboFieldDatasetDetail/?dataset_id=') !== false);

// private: invisible in anon search AND gated on direct click-through
list($st, $j, $r) = findProject('UNIQE2E_fpriv');
check('anon search: private field project invisible', $j && $j['total'] === 0,
	'total=' . ($j ? $j['total'] : "st=$st"));
list($st, $h, $body) = http_raw('GET', $BASE . '/fpl/' . $PFX . '_fp_priv', null);
check('anon /fpl/ private → Not Found page', strpos($body, 'Project Not Found') !== false);
list($st, $h, $body) = http_raw('GET', $BASE . '/fpl/' . $PFX . '_fp_priv', $sid);
check('owner /fpl/ private (1 dataset) → 302 into StraboFieldDatasetDetail', $st === 302
	&& strpos(locationHeader($h), '/StraboFieldDatasetDetail/?dataset_id=' . $PFX . '_fp_priv_ds') !== false,
	"st=$st " . locationHeader($h));

// ---------------------------------------------------------------------------
section('1b. FIELD — dataset detail API joins project json_tags to spots');

list($st, $h, $raw) = http_raw('GET',
	$BASE . '/StraboFieldDatasetDetail/api/spots.php?dataset_id=' . $NEO_DS, null);
$fc = json_decode($raw, true);
check('anon detail API 200 + parseable FeatureCollection', $st === 200
	&& is_array($fc) && isset($fc['features']), "st=$st");
check('all 3 fixture spots returned', $fc && count($fc['features']) === 3,
	'got ' . ($fc ? count($fc['features']) : 0));

$byId = array();
if ($fc) foreach ($fc['features'] as $f) {
	if (isset($f['properties']['id'])) $byId[(string)$f['properties']['id']] = $f;
}

$fa = isset($byId[(string)$NEO_SPOT_A]) ? $byId[(string)$NEO_SPOT_A] : null;
$fb = isset($byId[(string)$NEO_SPOT_B]) ? $byId[(string)$NEO_SPOT_B] : null;
$fcSpot = isset($byId[(string)$NEO_SPOT_C]) ? $byId[(string)$NEO_SPOT_C] : null;

$tagsA = ($fa && isset($fa['properties']['tags'])) ? $fa['properties']['tags'] : array();
$tagNamesA = array_map(function ($t) { return isset($t['name']) ? $t['name'] : ''; }, $tagsA);
check('spot A carries both spot-level tags', count($tagsA) === 2
	&& in_array('spse2e Alkali Unit', $tagNamesA) && in_array('spse2e Concept Tag', $tagNamesA),
	json_encode($tagNamesA));

$gu = null;
foreach ($tagsA as $t) if (isset($t['type']) && $t['type'] === 'geologic_unit') $gu = $t;
check('geologic_unit tag keeps its rock-type fields (F7 source)', $gu !== null
	&& $gu['rock_type'] === 'igneous' && $gu['igneous_rock_class'] === 'plutonic'
	&& $gu['plutonic_rock_types'] === 'alkali_granite'
	&& $gu['unit_label_abbreviation'] === 'Kag', json_encode($gu));
check('membership arrays stripped from payload tags', $gu !== null
	&& !isset($gu['spots']) && !isset($gu['features']));

$tagsB = ($fb && isset($fb['properties']['tags'])) ? $fb['properties']['tags'] : array();
$tagNamesB = array_map(function ($t) { return isset($t['name']) ? $t['name'] : ''; }, $tagsB);
check('spot B gets spots[] tag AND features-map tag', count($tagsB) === 2
	&& in_array('spse2e Concept Tag', $tagNamesB) && in_array('spse2e Feature Tag', $tagNamesB),
	json_encode($tagNamesB));

check('untagged spot C has no tags key', $fcSpot !== null
	&& !isset($fcSpot['properties']['tags']));

// ---------------------------------------------------------------------------
section('1c. FIELD — detail page sidebar images open in a lightbox');
// HTTP-only suite, can't run JS — assert the served page + asset carry the
// lightbox wiring (same pattern as smoke_test_search_ui asset checks).

list($st, $h, $body) = http_raw('GET',
	$BASE . '/StraboFieldDatasetDetail/?dataset_id=' . $NEO_DS, null);
check('detail page 200 for fixture dataset', $st === 200, "got $st");
check('detail page ships featherlight CSS',
	strpos($body, '/assets/js/featherlight/featherlight.css') !== false);

list($st, $h, $body) = http_raw('GET',
	$BASE . '/StraboFieldDatasetDetail/js/sidebar.js', null);
check('served sidebar.js marks image links for lightbox handling',
	strpos($body, 'ds-image-link') !== false
	&& strpos($body, "querySelectorAll('.ds-image-link')") !== false);
check('served sidebar.js opens featherlight with new-tab fallback',
	strpos($body, "\$.featherlight(link.getAttribute('href'), { type: 'image' })") !== false
	&& strpos($body, 'e.preventDefault()') !== false);

// ---------------------------------------------------------------------------
section('2. MICRO — search → result → /mpl/ redirect');

list($st, $j, $r) = findProject('UNIQE2E_mpub');
check('anon search finds public micro project', $r !== null
	&& $r['project_id'] === $PFX . '_mp_pub' && $r['project_subsystem'] === 'micro',
	'total=' . ($j ? $j['total'] : "st=$st"));

list($st, $h, $body) = http_raw('GET', $BASE . landingUrl($r), null);
check('anon /mpl/ answers 302 into the micro viewer', $st === 302, "got $st");
check('redirect targets microproject?id=<pkey>',
	strpos(locationHeader($h), 'microproject?id=' . $mpid) !== false, locationHeader($h));

// ---------------------------------------------------------------------------
section('3. EXP — search → result → /epl/; private gated (Phase 5 fix)');

list($st, $j, $r) = findProject('UNIQE2E_epub');
check('anon search finds public exp project', $r !== null
	&& $r['project_id'] === $PFX . '-exp-pub' && $r['project_subsystem'] === 'exp',
	'total=' . ($j ? $j['total'] : "st=$st"));

list($st, $h, $body) = http_raw('GET', $BASE . landingUrl($r), null);
check('anon /epl/ answers 302 into view_experiment', $st === 302, "got $st");
check('redirect targets /experimental/view_experiment?e=',
	strpos(locationHeader($h), '/experimental/view_experiment?e=') !== false, locationHeader($h));

// private exp: invisible in search; landing gate (added Phase 5 — the page
// previously served private experiment names + download links to anyone)
list($st, $j, $r) = findProject('UNIQE2E_epriv');
check('anon search: private exp project invisible', $j && $j['total'] === 0,
	'total=' . ($j ? $j['total'] : "st=$st"));
list($st, $h, $body) = http_raw('GET', $BASE . '/epl/' . $PFX . '-exp-priv', null);
check('anon /epl/ private → Not Found page', strpos($body, 'Project Not Found') !== false);
check('anon /epl/ private leaks NO experiment name', strpos($body, 'PRIVMARKER_expname') === false);
list($st, $h, $body) = http_raw('GET', $BASE . '/epl/' . $PFX . '-exp-priv', $sid);
check('owner /epl/ private → 302 into view_experiment', $st === 302, "got $st");

// ---------------------------------------------------------------------------
section('4. SAMPLES — U8=samples search routes to the host landing');

list($st, $j, $r) = findProject('UNIQE2E_spub', array('samples'));
check('anon samples-subsystem search finds the fan-out row', $r !== null
	&& $r['project_id'] === $PFX . '_fp_pub'
	&& $r['match_counts']['sample'] === 1,
	'total=' . ($j ? $j['total'] : "st=$st"));
list($st, $h, $body) = http_raw('GET', $BASE . landingUrl($r), null);
check('sample result click-through lands on host project', $st === 200
	&& strpos($body, 'spse2e Field DS') !== false, "got $st");

// ---------------------------------------------------------------------------
section('5. IMAGES pathway — image result → parent project landing');

$dsl = array('pathway' => 'images', 'criteria' => array(
	array('id' => 'U1', 'value' => 'UNIQE2E_ipub')));
list($st, $j) = searchProxy(null, $dsl);
$ir = ($j && isset($j['results'][0])) ? $j['results'][0] : null;
check('anon image search finds the image', $ir !== null
	&& $ir['image_id'] === $PFX . '_img1', 'total=' . ($j ? $j['total'] : "st=$st"));
check('image result carries the parent project', $ir !== null
	&& $ir['project_id'] === $PFX . '_fp_pub' && $ir['project_name'] !== null);
if ($ir !== null) {
	list($st, $h, $body) = http_raw('GET',
		$BASE . landingUrl(array('project_subsystem' => $ir['project_subsystem'],
		                         'project_id' => $ir['project_id'])), null);
	check('image click-through 200 on parent landing', $st === 200
		&& strpos($body, 'spse2e Field DS') !== false, "got $st");
}

// ---------------------------------------------------------------------------
section('6. Teardown + zero residue');

e2e_cleanup($db, $UPK, $PFX);
e2e_neo_cleanup($neodb, $UPK);
foreach ($sessionFiles as $f) @unlink($f);
$neoResidue = $neodb->get_results("MATCH (n) WHERE n.userpkey = $UPK RETURN count(n) AS c");
check('zero Neo4j residue', $neoResidue && (int)$neoResidue[0]->value('c') === 0);
check('zero item_hit residue',
	(int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE '{$PFX}_%'") === 0);
check('zero image_hit residue',
	(int)$db->get_var("SELECT count(*) FROM strabosearch.image_hit WHERE project_id LIKE '{$PFX}_%'") === 0);
check('zero mirror residue',
	(int)$db->get_var("SELECT count(*) FROM project WHERE user_pkey = $UPK") === 0);
check('zero exp residue',
	(int)$db->get_var("SELECT count(*) FROM straboexp.project WHERE userpkey = $UPK") === 0);
check('zero micro residue',
	(int)$db->get_var("SELECT count(*) FROM strabomicro.micro_projectmetadata WHERE userpkey = $UPK") === 0);

// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('=', 70) . PHP_EOL;
if ($failures) {
	echo count($failures) . " FAILURE(S):" . PHP_EOL;
	foreach ($failures as $f) echo "  - $f" . PHP_EOL;
	exit(1);
}
echo "ALL CHECKS PASSED." . PHP_EOL;
exit(0);
