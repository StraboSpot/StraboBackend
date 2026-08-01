<?php
/**
 * File: smoke_test_verify_extended.php
 * Description: Permanent smoke suite for the §5.6.1 nightly checker
 *              (searchdb/verify_extended.php). Seeds a small cross-subsystem
 *              fixture set under isolated userpkey 94540, builds a clean
 *              index through the same StraboSearchSync primitives the
 *              verifier heals with, then injects every drift class the
 *              checker claims to catch and asserts detect → heal →
 *              re-verify convergence, exercising the real CLI (exit codes
 *              included) via --source-userpkey scoping.
 *
 *              Coverage:
 *                BASELINE  clean scoped run passes all six checks, exit 0.
 *                FIELD     missing spot row / stale (backdated
 *                          source_modified) / extra ghost spot / extra ghost
 *                          PROJECT slice → heal restores + removes; exit
 *                          codes 2 (detect) / 1 (healed) / 0 (clean).
 *                IMAGES    missing image row + extra ghost image row.
 *                MICRO     missing micrograph item row + backdated micro
 *                          image row → one syncMicroProject heal fixes both
 *                          tables.
 *                EXP       missing + ghost experiment rows; id-COLLISION
 *                          regression (e.id is not unique per project — the
 *                          set-membership stale rule must accept whichever
 *                          copy the index holds, and still converge after a
 *                          copy is deleted).
 *                SAMPLES   deleted + backdated fan-out rows (one touchSample
 *                          heals the whole sample); spine deletion sweeps
 *                          all fan-out rows as 'extra'.
 *                ACL       index flag flipped vs source, and source flag
 *                          flipped vs index — touchProjectMeta heal fixes
 *                          item_hit AND image_hit.
 *                BUDGET    --max-heal smaller than the drift leaves it
 *                          unhealed with exit 2.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_verify_extended.php
 *
 * @package    StraboSearch verify
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/searchdb/sync/StraboSearchSync.php';

$TEST_UPK = 94540;               // disjoint from every other suite (94501..94530)
$PROJ     = 945401001;           // Field project (numeric — Cypher embeds unquoted)
$DS1      = 945402001;
$SPOT1    = 945403001;
$SPOT2    = 945403002;
$IMG1     = 945404001;
$GHOST_SPOT  = 945403999;
$GHOST_PROJ  = 945409999;
$GHOST_PSPOT = 945403998;
$GHOST_IMG   = 945404999;
$PFX      = 'spverify94540';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . '== ' . $t . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

function itemCount($db, $where) {
	return (int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit WHERE $where");
}
function imageCount($db, $where) {
	return (int)$db->get_var("SELECT count(*) FROM strabosearch.image_hit WHERE $where");
}

/** Run the real CLI scoped to the fixture user; returns [exitCode, output]. */
function runVerify($extraArgs = '') {
	global $TEST_UPK;
	$out = array();
	$code = 0;
	exec('php /srv/app/www/searchdb/verify_extended.php --source-userpkey=' . $TEST_UPK
		. ' ' . $extraArgs . ' 2>&1', $out, $code);
	return array($code, implode("\n", $out));
}

function verify_cleanup($db, $neodb, $TEST_UPK, $PFX) {
	// User-anchored Neo4j teardown (bare Spot-id matches are 1.7M label scans).
	$neodb->query("MATCH (u:User {userpkey: $TEST_UPK})-[:HAS_PROJECT]->(p:Project)
		-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot)
		OPTIONAL MATCH (s)-[:HAS_IMAGE]->(i:Image)
		DETACH DELETE i, s");
	$neodb->query("MATCH (u:User {userpkey: $TEST_UPK})-[:HAS_PROJECT]->(p:Project)
		OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset)
		DETACH DELETE d, p");
	$neodb->query("MATCH (u:User {userpkey: $TEST_UPK}) DETACH DELETE u");
	$db->query("DELETE FROM strabosearch.item_hit WHERE item_userpkey = $TEST_UPK OR project_userpkey = $TEST_UPK");
	$db->query("DELETE FROM strabosearch.image_hit WHERE image_userpkey = $TEST_UPK OR project_userpkey = $TEST_UPK");
	$db->query("DELETE FROM strabosamples.sample_subsystem_links WHERE sample_userpkey = $TEST_UPK");
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey = $TEST_UPK");
	// Micro chain bottom-up (no cascades in strabomicro).
	$db->query("DELETE FROM strabomicro.micro_micrographmetadata WHERE sample_id IN
		(SELECT s.id FROM strabomicro.micro_samplemetadata s
		   JOIN strabomicro.micro_datasetmetadata d ON s.dataset_id = d.id
		   JOIN strabomicro.micro_projectmetadata p ON d.project_id = p.id
		  WHERE p.userpkey = $TEST_UPK)");
	$db->query("DELETE FROM strabomicro.micro_samplemetadata WHERE dataset_id IN
		(SELECT d.id FROM strabomicro.micro_datasetmetadata d
		   JOIN strabomicro.micro_projectmetadata p ON d.project_id = p.id
		  WHERE p.userpkey = $TEST_UPK)");
	$db->query("DELETE FROM strabomicro.micro_datasetmetadata WHERE project_id IN
		(SELECT id FROM strabomicro.micro_projectmetadata WHERE userpkey = $TEST_UPK)");
	$db->query("DELETE FROM strabomicro.micro_projectmetadata WHERE userpkey = $TEST_UPK");
	$db->query("DELETE FROM straboexp.experiment WHERE userpkey = $TEST_UPK");
	$db->query("DELETE FROM straboexp.project WHERE userpkey = $TEST_UPK");
	$db->query("DELETE FROM project WHERE user_pkey = $TEST_UPK");
	$db->query("DELETE FROM users WHERE pkey = $TEST_UPK");
}

// ===========================================================================
section('0. Cleanup + fixtures across all four subsystems');

verify_cleanup($db, $neodb, $TEST_UPK, $PFX);
$db->query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active)
	VALUES ($TEST_UPK, 'spverify', 'fixture', 'spverify-$TEST_UPK@test.strabospot.org', 'x', 'x', false)");

// FIELD: User → Project (PG row PUBLIC) → Dataset → 2 spots, one with image.
$neodb->query("CREATE (u:User {userpkey: $TEST_UPK, email: 'spverify-$TEST_UPK@test.strabospot.org'})");
$neodb->query("CREATE (p:Project {id: $PROJ, userpkey: $TEST_UPK,
	desc_project_name: 'spverify Field Project VRFTOK_projname'})");
$neodb->query("MATCH (u:User {userpkey: $TEST_UPK}), (p:Project {id: $PROJ})
	CREATE (u)-[:HAS_PROJECT]->(p)");
$db->query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic)
	VALUES ($TEST_UPK, 'spverify Field Project VRFTOK_projname', '$PROJ', TRUE)");
$neodb->query("MATCH (p:Project {id: $PROJ})
	CREATE (d:Dataset {id: $DS1, userpkey: $TEST_UPK, name: 'spverify DS'})
	CREATE (p)-[:HAS_DATASET]->(d)");
$neodb->query("MATCH (d:Dataset {id: $DS1}) CREATE (s:Spot {
	id: $SPOT1, userpkey: $TEST_UPK, name: 'spverify Spot One VRFTOK_spotone',
	wkt: 'POINT (-118.25 34.05)', modified_timestamp: 1722400000000
}) CREATE (d)-[:HAS_SPOT]->(s)");
$neodb->query("MATCH (d:Dataset {id: $DS1}) CREATE (s:Spot {
	id: $SPOT2, userpkey: $TEST_UPK, name: 'spverify Spot Two VRFTOK_spottwo',
	wkt: 'POINT (-118.26 34.06)', modified_timestamp: 1722400002000
}) CREATE (d)-[:HAS_SPOT]->(s)");
$neodb->query("MATCH (s:Spot {id: $SPOT1}) CREATE (i:Image {
	id: $IMG1, userpkey: $TEST_UPK, image_type: 'photo', title: 'spverify image',
	annotated: '1', filename: '$IMG1.jpg', modified_timestamp: 1722400001000
}) CREATE (s)-[:HAS_IMAGE]->(i)");

// MICRO: project (ms-epoch varchar timestamp) → dataset → sample → micrograph.
$MSTRABO = $PFX . '_mp';
$db->query("INSERT INTO strabomicro.micro_projectmetadata
	(strabo_id, userpkey, name, ispublic, modifiedtimestamp, notes)
	VALUES ('$MSTRABO', $TEST_UPK, 'spverify Micro Project', FALSE, '1722400005000', '')");
$mpid = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_datasetmetadata (project_id, strabo_id, name)
	VALUES ($mpid, '{$MSTRABO}_ds', 'mds')");
$mdid = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_samplemetadata
	(dataset_id, strabo_id, label, sampleid, longitude, latitude, samplenotes)
	VALUES ($mdid, '{$MSTRABO}_samp', 'spverify micro sample', 'VS-1', -100.5, 40.5, '')");
$msid = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_micrographmetadata
	(sample_id, strabo_id, name, notes, imagetype, width, height)
	VALUES ($msid, '{$MSTRABO}_mg1', 'spverify Micrograph', '', 'Backscatter Electron (BSE)', 1024, 768)");

// EXP: project + one experiment.
$EUUID = $PFX . '-exp-uuid';
$db->query("INSERT INTO straboexp.project (userpkey, uuid, name, notes, ispublic)
	VALUES ($TEST_UPK, '$EUUID', 'spverify Exp Project', '', TRUE)");
$eppk = (int)$db->insert_id;
$db->query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, json, modified_timestamp)
	VALUES ($eppk, $TEST_UPK, '{$PFX}_exp1', '{}', now())");
$epk = (int)$db->insert_id;

// SAMPLES: one spine sample, fan-out to all three subsystems (field resolves
// through the indexed SPOT1 row once the Field slice is built below).
$S1 = $PFX . '_s1';
$db->query("INSERT INTO strabosamples.samples
	(id, userpkey, name, created_by, modified_by)
	VALUES ('$S1', $TEST_UPK, 'spverify Sample One', $TEST_UPK, $TEST_UPK)");
$db->query("INSERT INTO strabosamples.sample_subsystem_links
	(sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
	VALUES ('$S1', $TEST_UPK, 'field', '$SPOT1', $TEST_UPK, '{}'),
	       ('$S1', $TEST_UPK, 'micro', 'mref', $TEST_UPK, '{\"project_strabo_id\": \"$MSTRABO\"}'),
	       ('$S1', $TEST_UPK, 'experimental', 'eref', $TEST_UPK, '{\"project_uuid\": \"$EUUID\"}')");

// Build the clean index through the sync primitives (same code heals use).
check('index: touchSpot SPOT1', StraboSearchSync::touchSpot($db, $neodb, $SPOT1, $TEST_UPK) === true);
check('index: touchSpot SPOT2', StraboSearchSync::touchSpot($db, $neodb, $SPOT2, $TEST_UPK) === true);
check('index: syncMicroProject', StraboSearchSync::syncMicroProject($db, $mpid, $MSTRABO, $TEST_UPK) === true);
check('index: touchExperiment', StraboSearchSync::touchExperiment($db, $epk) === true);
check('index: touchSample', StraboSearchSync::touchSample($db, $S1, $TEST_UPK) === true);
check('baseline rows: 2 spots + 1 micrograph + 1 experiment + 3 sample fan-out',
	itemCount($db, "item_userpkey = $TEST_UPK") === 7,
	'got ' . itemCount($db, "item_userpkey = $TEST_UPK"));
check('baseline image rows: 1 field + 1 micro',
	imageCount($db, "image_userpkey = $TEST_UPK") === 2,
	'got ' . imageCount($db, "image_userpkey = $TEST_UPK"));

// ===========================================================================
section('1. BASELINE — clean scoped run passes everything');

list($code, $out) = runVerify();
check('clean run exit 0', $code === 0, "exit $code");
check('clean run says PASS', strpos($out, 'VERIFY EXTENDED: PASS') !== false);
foreach (array('field', 'images', 'micro', 'exp', 'samples', 'acl') as $c) {
	check("clean run: $c OK", preg_match('/' . $c . ':\s+OK/', $out) === 1);
}

// ===========================================================================
section('2. FIELD — missing / stale / extra spot + extra project slice');

$W1 = "item_type='spot' AND item_id='$SPOT1' AND item_userpkey=$TEST_UPK";
$W2 = "item_type='spot' AND item_id='$SPOT2' AND item_userpkey=$TEST_UPK";
$db->query("DELETE FROM strabosearch.item_hit WHERE $W1");
$db->query("UPDATE strabosearch.item_hit SET source_modified = to_timestamp(1000000000) WHERE $W2");
$db->query("INSERT INTO strabosearch.item_hit
	(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem, project_ispublic)
	VALUES ('spot', '$GHOST_SPOT', $TEST_UPK, '$PROJ', $TEST_UPK, 'field', TRUE),
	       ('spot', '$GHOST_PSPOT', $TEST_UPK, '$GHOST_PROJ', $TEST_UPK, 'field', TRUE)");

list($code, $out) = runVerify('--only=field');
check('field drift detected, exit 2', $code === 2, "exit $code");
check('field drift count = 4', strpos($out, 'RESULT: 4 drifted row(s)') !== false, $out);

list($code, $out) = runVerify('--only=field --heal');
check('field heal run exit 1', $code === 1, "exit $code");
check('field heal reports HEALED', strpos($out, 'VERIFY EXTENDED: HEALED') !== false);

check('missing spot restored', itemCount($db, $W1) === 1);
$r = $db->get_row("SELECT * FROM strabosearch.item_hit WHERE $W1 LIMIT 1");
check('restored row carries project context', $r && $r->project_id === (string)$PROJ
	&& $r->project_ispublic === 't');
check('stale spot re-synced',
	(int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit
		WHERE $W2 AND abs(extract(epoch from (source_modified - to_timestamp(1722400002)))) <= 2") === 1);
check('ghost spot removed', itemCount($db, "item_id='$GHOST_SPOT'") === 0);
check('ghost project slice removed', itemCount($db, "project_id='$GHOST_PROJ'") === 0);

list($code, $out) = runVerify('--only=field');
check('field clean after heal, exit 0', $code === 0, "exit $code");

// ===========================================================================
section('3. IMAGES — missing + ghost image rows');

$WI1 = "image_subsystem='field' AND image_id='$IMG1' AND image_userpkey=$TEST_UPK";
$db->query("DELETE FROM strabosearch.image_hit WHERE $WI1");
$db->query("INSERT INTO strabosearch.image_hit
	(image_id, image_subsystem, image_userpkey, project_id, project_userpkey,
	 project_subsystem, project_ispublic, filename)
	VALUES ('$GHOST_IMG', 'field', $TEST_UPK, '$PROJ', $TEST_UPK, 'field', TRUE, 'ghost.jpg')");

list($code, $out) = runVerify('--only=images');
check('image drift detected, exit 2', $code === 2, "exit $code");

list($code, $out) = runVerify('--only=images --heal');
check('image heal run exit 1', $code === 1, "exit $code");
check('missing image restored via parent-spot touch', imageCount($db, $WI1) === 1);
check('ghost image removed', imageCount($db, "image_id='$GHOST_IMG'") === 0);

list($code, $out) = runVerify('--only=images');
check('images clean after heal, exit 0', $code === 0, "exit $code");

// ===========================================================================
section('4. MICRO — missing item row + backdated image row, one project heal');

$WM  = "item_type='micrograph' AND item_id='{$MSTRABO}_mg1' AND item_userpkey=$TEST_UPK";
$WMI = "image_subsystem='micro' AND image_id='{$MSTRABO}_mg1' AND image_userpkey=$TEST_UPK";
$db->query("DELETE FROM strabosearch.item_hit WHERE $WM");
$db->query("UPDATE strabosearch.image_hit SET source_modified = now() - interval '10 days' WHERE $WMI");

list($code, $out) = runVerify('--only=micro');
check('micro drift detected, exit 2', $code === 2, "exit $code");
check('micro drift = 2 rows (item missing + image stale)',
	strpos($out, 'RESULT: 2 drifted row(s)') !== false, $out);

list($code, $out) = runVerify('--only=micro --heal');
check('micro heal run exit 1', $code === 1, "exit $code");
check('micrograph item row restored', itemCount($db, $WM) === 1);
check('micro image row re-synced',
	(int)$db->get_var("SELECT count(*) FROM strabosearch.image_hit
		WHERE $WMI AND abs(extract(epoch from (source_modified - to_timestamp(1722400005)))) <= 2") === 1);

list($code, $out) = runVerify('--only=micro');
check('micro clean after heal, exit 0', $code === 0, "exit $code");

// ===========================================================================
section('5. EXP — missing + ghost rows; id-collision regression');

$WE = "item_type='experiment' AND item_id='{$PFX}_exp1' AND item_userpkey=$TEST_UPK";
$db->query("DELETE FROM strabosearch.item_hit WHERE $WE");
$db->query("INSERT INTO strabosearch.item_hit
	(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem, project_ispublic)
	VALUES ('experiment', '{$PFX}_ghost', $TEST_UPK, '$EUUID', $TEST_UPK, 'exp', FALSE)");

list($code, $out) = runVerify('--only=exp');
check('exp drift detected, exit 2', $code === 2, "exit $code");

list($code, $out) = runVerify('--only=exp --heal');
check('exp heal run exit 1', $code === 1, "exit $code");
check('experiment row restored', itemCount($db, $WE) === 1);
check('ghost experiment removed', itemCount($db, "item_id='{$PFX}_ghost'") === 0);

// COLLISION regression: a second experiment with the SAME id in the same
// project. The index legitimately holds either copy — no perpetual drift.
$db->query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, json, modified_timestamp)
	VALUES ($eppk, $TEST_UPK, '{$PFX}_exp1', '{}', now() - interval '1 hour')");
$epk2 = (int)$db->insert_id;
StraboSearchSync::touchExperiment($db, $epk2);   // index now holds copy 2's timestamp
list($code, $out) = runVerify('--only=exp');
check('collided id holding copy-2 timestamp is NOT drift (membership rule)',
	$code === 0, "exit $code -- $out");
$db->query("DELETE FROM straboexp.experiment WHERE pkey = $epk2");
list($code, $out) = runVerify('--only=exp');
check('deleting the held copy IS drift', $code === 2, "exit $code");
list($code, $out) = runVerify('--only=exp --heal');
check('collision heal converges to surviving copy', $code === 1, "exit $code");
list($code, $out) = runVerify('--only=exp');
check('exp clean after collision heal, exit 0', $code === 0, "exit $code");

// ===========================================================================
section('6. SAMPLES — fan-out drift + spine deletion sweep');

$WS = "item_type='sample' AND item_id='$S1' AND item_userpkey=$TEST_UPK";
$db->query("DELETE FROM strabosearch.item_hit WHERE $WS AND project_subsystem='exp'");
$db->query("UPDATE strabosearch.item_hit SET source_modified = to_timestamp(1000000000)
	WHERE $WS AND project_subsystem='field'");

list($code, $out) = runVerify('--only=samples');
check('samples drift detected, exit 2', $code === 2, "exit $code");
check('samples drift = 2 rows, 1 sample',
	strpos($out, 'drifted rows: 2 across 1 sample(s)') !== false, $out);

list($code, $out) = runVerify('--only=samples --heal');
check('samples heal run exit 1', $code === 1, "exit $code");
check('full 3-way fan-out restored', itemCount($db, $WS) === 3, 'got ' . itemCount($db, $WS));

// Spine deletion: all fan-out rows become 'extra'; touchSample sweeps them.
$S2 = $PFX . '_s2';
$db->query("INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by)
	VALUES ('$S2', $TEST_UPK, 'spverify doomed sample', $TEST_UPK, $TEST_UPK)");
$db->query("INSERT INTO strabosamples.sample_subsystem_links
	(sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
	VALUES ('$S2', $TEST_UPK, 'experimental', 'eref2', $TEST_UPK, '{\"project_uuid\": \"$EUUID\"}')");
StraboSearchSync::touchSample($db, $S2, $TEST_UPK);
check('doomed sample indexed', itemCount($db, "item_id='$S2'") === 1);
$db->query("DELETE FROM strabosamples.sample_subsystem_links WHERE sample_id='$S2' AND sample_userpkey=$TEST_UPK");
$db->query("DELETE FROM strabosamples.samples WHERE id='$S2' AND userpkey=$TEST_UPK");

list($code, $out) = runVerify('--only=samples --heal');
check('spine-deletion heal run exit 1', $code === 1, "exit $code");
check('deleted spine fan-out swept', itemCount($db, "item_id='$S2'") === 0);
list($code, $out) = runVerify('--only=samples');
check('samples clean after heals, exit 0', $code === 0, "exit $code");

// ===========================================================================
section('7. ACL — flag drift in both directions, both tables');

// Index flipped private while source says public.
$db->query("UPDATE strabosearch.item_hit SET project_ispublic = FALSE
	WHERE project_subsystem='field' AND project_id='$PROJ' AND project_userpkey=$TEST_UPK");
$db->query("UPDATE strabosearch.image_hit SET project_ispublic = FALSE
	WHERE image_subsystem='field' AND project_id='$PROJ' AND project_userpkey=$TEST_UPK");

list($code, $out) = runVerify('--only=acl');
check('acl drift detected, exit 2', $code === 2, "exit $code");

list($code, $out) = runVerify('--only=acl --heal');
check('acl heal run exit 1', $code === 1, "exit $code");
check('item rows flipped back public', itemCount($db,
	"project_subsystem='field' AND project_id='$PROJ' AND project_userpkey=$TEST_UPK AND project_ispublic = FALSE") === 0);
check('image rows flipped back public', imageCount($db,
	"image_subsystem='field' AND project_id='$PROJ' AND project_userpkey=$TEST_UPK AND project_ispublic = FALSE") === 0);

// Source flipped private while index still says public.
$db->query("UPDATE project SET ispublic = FALSE WHERE strabo_project_id='$PROJ' AND user_pkey=$TEST_UPK");
list($code, $out) = runVerify('--only=acl --heal');
check('source-side flip healed, exit 1', $code === 1, "exit $code");
check('index rows now private', itemCount($db,
	"project_subsystem='field' AND project_id='$PROJ' AND project_userpkey=$TEST_UPK AND project_ispublic = TRUE") === 0);
list($code, $out) = runVerify('--only=acl');
check('acl clean after heals, exit 0', $code === 0, "exit $code");

// ===========================================================================
section('8. BUDGET — --max-heal smaller than the drift leaves exit 2');

$db->query("DELETE FROM strabosearch.item_hit WHERE $WS AND project_subsystem='exp'");
$db->query("DELETE FROM strabosearch.item_hit WHERE $WS AND project_subsystem='micro'");
list($code, $out) = runVerify('--only=samples --heal --max-heal=1');
check('budget-capped heal exits 2', $code === 2, "exit $code");
check('budget-capped heal reports unhealed', preg_match('/unhealed:\s+[1-9]/', $out) === 1, $out);
list($code, $out) = runVerify('--only=samples --heal');
check('uncapped heal repairs the remainder, exit 1', $code === 1, "exit $code");
list($code, $out) = runVerify();
check('FINAL full scoped run clean, exit 0', $code === 0, "exit $code");

// ===========================================================================
section('9. Teardown + zero residue');

verify_cleanup($db, $neodb, $TEST_UPK, $PFX);
check('zero item_hit residue', itemCount($db, "item_userpkey = $TEST_UPK OR project_userpkey = $TEST_UPK") === 0);
check('zero image_hit residue', imageCount($db, "image_userpkey = $TEST_UPK OR project_userpkey = $TEST_UPK") === 0);
check('zero spine residue', (int)$db->get_var("SELECT count(*) FROM strabosamples.samples WHERE userpkey = $TEST_UPK") === 0);
check('zero exp residue', (int)$db->get_var("SELECT count(*) FROM straboexp.experiment WHERE userpkey = $TEST_UPK") === 0);
check('zero micro residue', (int)$db->get_var("SELECT count(*) FROM strabomicro.micro_projectmetadata WHERE userpkey = $TEST_UPK") === 0);
check('zero pg project residue', (int)$db->get_var("SELECT count(*) FROM project WHERE user_pkey = $TEST_UPK") === 0);
$neoResidue = 0;
foreach ($neodb->query("MATCH (u:User {userpkey: $TEST_UPK}) RETURN count(u) AS n") as $r) {
	$neoResidue = (int)$r->get('n');
}
check('zero Neo4j residue', $neoResidue === 0);

// ===========================================================================
echo PHP_EOL . str_repeat('=', 70) . PHP_EOL;
if (empty($failures)) {
	echo "ALL CHECKS PASSED" . PHP_EOL;
	exit(0);
}
echo count($failures) . " FAILURE(S):" . PHP_EOL;
foreach ($failures as $f) echo "  - $f" . PHP_EOL;
exit(1);
