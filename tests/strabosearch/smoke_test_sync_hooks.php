<?php
/**
 * File: smoke_test_sync_hooks.php
 * Description: Permanent smoke suite for the StraboSearch §5.3 incremental
 *              live-sync (searchdb/sync/StraboSearchSync.php + the hook
 *              wiring in the four subsystems' write paths).
 *
 *              Hermetic: all fixtures live under isolated userpkey 94530
 *              (numeric id prefix 94530xxxxx, disjoint from every other
 *              suite). Seeds sources directly, drives the sync layer (and,
 *              for Field, the real strabospotclass write methods so the
 *              in-class hook wiring is exercised), asserts against
 *              strabosearch.item_hit / image_hit, tears down.
 *
 *              Coverage:
 *                FIELD  direct: touchSpot columns + idempotency + multi-
 *                       dataset dedupe + rename re-extract + image rows +
 *                       touchImage resolve/stale-removal + suppression +
 *                       syncFieldDataset batch + removeSpot +
 *                       touchProjectMeta ispublic + removeFieldProject.
 *                FIELD  class hooks: insertSpot create (pre-dataset-link →
 *                       not yet indexable) → update (indexed via hook) →
 *                       deleteSingleSpot (hook removes) →
 *                       deleteSingleDataset (pre-delete enumeration hook).
 *                MICRO  syncMicroProject slice rebuild (item + image +
 *                       facets) + stale sweep on shrink + ispublic meta +
 *                       removeMicroProject.
 *                EXP    touchExperiment (owner keying + facets) +
 *                       touchExpProject rename re-extract +
 *                       removeExperiment + removeExpProject.
 *                SAMPLES touchSample 3-way fan-out ('experimental'→'exp'
 *                       translation) + spine-edit refresh + link-removal
 *                       shrink; service-hook E2E: upsertSample indexes
 *                       without a manual touch, removeSubsystemSample
 *                       JSONB-null branch shrinks, deleteSample removes.
 *                HARDENING never-throw on garbage input; no leaked
 *                       advisory locks on this connection.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_sync_hooks.php
 *
 * @package    StraboSearch sync
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/geophp/geoPHP.inc';
require_once '/srv/app/www/db/strabospotclass.php';
require_once '/srv/app/www/searchdb/sync/StraboSearchSync.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

$TEST_UPK  = 94530;
$PROJ      = 945301001;          // Field project (numeric ids — class Cypher embeds them unquoted)
$DS1       = 945302001;
$DS2       = 945302002;
$SPOT1     = 945303001;
$SPOT2     = 945303002;
$SPOT3     = 945303003;
$SPOT_E2E  = 945303101;          // class-hook E2E spot
$SPOT_DS2  = 945303201;          // lives in DS2 for the dataset-delete E2E
$IMG1      = 945304001;
$IMG_STALE = 945304002;
$PFX       = 'spsync94530';

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
function itemRow($db, $where) {
	return $db->get_row("SELECT * FROM strabosearch.item_hit WHERE $where LIMIT 1");
}
function tsvHas($db, $table, $where, $lexeme) {
	return (bool)$db->get_var("SELECT 1 FROM strabosearch.$table
		WHERE $where AND searchtext_tsv @@ plainto_tsquery('" . pg_escape_string($lexeme) . "') LIMIT 1");
}

function sync_cleanup($db, $neodb, $TEST_UPK, $PFX) {
	// Delete Neo4j fixtures via the User-anchored walk — bare
	// `(:Spot {id: ...})` lookups are LABEL SCANS over the dev restore's
	// 1.7M fat Spot nodes (minutes per query; there is no schema index on
	// Spot.id). The User label is small, and everything we seed hangs off
	// the fixture User's HAS_PROJECT edge.
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
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey = $TEST_UPK");
	$db->query("DELETE FROM strabomicro.micro_projectmetadata WHERE userpkey = $TEST_UPK");
	$db->query("DELETE FROM straboexp.experiment WHERE userpkey = $TEST_UPK");
	$db->query("DELETE FROM straboexp.project WHERE userpkey = $TEST_UPK");
	$db->query("DELETE FROM project WHERE user_pkey = $TEST_UPK");
	$db->query("DELETE FROM dataset WHERE user_pkey = $TEST_UPK");
	$db->query("DELETE FROM versions WHERE userpkey = $TEST_UPK");
	$db->query("DELETE FROM users WHERE pkey = $TEST_UPK");
}

// ===========================================================================
section('0. Cleanup + base fixtures');

sync_cleanup($db, $neodb, $TEST_UPK, $PFX);
$db->query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active)
	VALUES ($TEST_UPK, 'spsync', 'fixture', 'spsync-$TEST_UPK@test.strabospot.org', 'x', 'x', false)");

// Field graph: User → Project (PG row public) → DS1 + DS2.
$neodb->query("CREATE (u:User {userpkey: $TEST_UPK, email: 'spsync-$TEST_UPK@test.strabospot.org'})");
$neodb->query("CREATE (p:Project {id: $PROJ, userpkey: $TEST_UPK,
	desc_project_name: 'spsync Field Project SYNCTOK_projname'})");
$neodb->query("MATCH (u:User {userpkey: $TEST_UPK}), (p:Project {id: $PROJ})
	CREATE (u)-[:HAS_PROJECT]->(p)");
$db->query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic)
	VALUES ($TEST_UPK, 'spsync Field Project SYNCTOK_projname', '$PROJ', TRUE)");
foreach (array($DS1 => 'spsync DS One', $DS2 => 'spsync DS Two') as $dsid => $dsname) {
	$neodb->query("MATCH (p:Project {id: $PROJ})
		CREATE (d:Dataset {id: $dsid, userpkey: $TEST_UPK, name: '$dsname'})
		CREATE (p)-[:HAS_DATASET]->(d)");
}
echo "  seeded user $TEST_UPK + field project $PROJ (public) + 2 datasets\n";

// ===========================================================================
section('1. FIELD — touchSpot columns + idempotency');

$neodb->query("MATCH (d:Dataset {id: $DS1}) CREATE (s:Spot {
	id: $SPOT1, userpkey: $TEST_UPK,
	name: 'spsync Spot One SYNCTOK_spotone',
	notes: 'granite outcrop SYNCTOK_notesone',
	wkt: 'POINT (-118.25 34.05)',
	modified_timestamp: 1722400000000,
	date: '2026-07-15',
	json_orientation_data: '[{\"strike\": 145, \"dip\": 38, \"type\": \"planar_orientation\", \"feature_type\": \"bedding\"}]',
	json_samples: '[{\"id\": 999, \"sample_id_name\": \"SY-1\"}]'
}) CREATE (d)-[:HAS_SPOT]->(s)");
$neodb->query("MATCH (s:Spot {id: $SPOT1}) CREATE (i:Image {
	id: $IMG1, userpkey: $TEST_UPK, image_type: 'photo',
	title: 'spsync Image SYNCTOK_imgtitle', caption: 'outcrop face',
	annotated: '1', filename: '$IMG1', modified_timestamp: 1722400001000
}) CREATE (s)-[:HAS_IMAGE]->(i)");

$ok = StraboSearchSync::touchSpot($db, $neodb, $SPOT1, $TEST_UPK);
check('touchSpot returns true', $ok === true);

$W1 = "item_type='spot' AND item_id='$SPOT1' AND item_userpkey=$TEST_UPK";
check('one item row', itemCount($db, $W1) === 1, 'got ' . itemCount($db, $W1));
$r = itemRow($db, $W1);
check('project context', $r && $r->project_id === (string)$PROJ && (int)$r->project_userpkey === $TEST_UPK);
check('project_ispublic TRUE from PG', $r && $r->project_ispublic === 't');
check('has_orientation TRUE', $r && $r->has_orientation === 't');
check('has_samples TRUE', $r && $r->has_samples === 't');
check('has_images TRUE', $r && $r->has_images === 't');
check('orientation_strike carries 145', $r && strpos((string)$r->orientation_strike, '145') !== false,
	(string)($r ? $r->orientation_strike : 'null'));
check('location populated', $r && $r->location !== null);
check('date_value from spot.date', $r && $r->date_value === '2026-07-15');
check('searchtext has spot token', tsvHas($db, 'item_hit', $W1, 'SYNCTOK_spotone'));
check('searchtext has project name token', tsvHas($db, 'item_hit', $W1, 'SYNCTOK_projname'));

$WI1 = "image_subsystem='field' AND image_id='$IMG1' AND image_userpkey=$TEST_UPK";
check('one image row', imageCount($db, $WI1) === 1, 'got ' . imageCount($db, $WI1));
$ir = $db->get_row("SELECT * FROM strabosearch.image_hit WHERE $WI1 LIMIT 1");
check('image parent_spot_id', $ir && $ir->parent_spot_id === (string)$SPOT1);
check('image inherits orientation', $ir && strpos((string)$ir->orientation_strike, '145') !== false);
check('image annotated TRUE', $ir && $ir->annotated === 't');
check('image type normalized photo', $ir && $ir->image_type === 'photo');

StraboSearchSync::touchSpot($db, $neodb, $SPOT1, $TEST_UPK);
check('re-touch idempotent (item)', itemCount($db, $W1) === 1);
check('re-touch idempotent (image)', imageCount($db, $WI1) === 1);

// ===========================================================================
section('2. FIELD — multi-dataset dedupe + rename re-extract');

$neodb->query("MATCH (d:Dataset {id: $DS2}), (s:Spot {id: $SPOT1}) CREATE (d)-[:HAS_SPOT]->(s)");
StraboSearchSync::touchSpot($db, $neodb, $SPOT1, $TEST_UPK);
check('spot in 2 datasets of same project → still 1 row', itemCount($db, $W1) === 1,
	'got ' . itemCount($db, $W1));

$neodb->query("MATCH (s:Spot {id: $SPOT1}) SET s.name = 'spsync renamed SYNCTOK_renamed'");
StraboSearchSync::touchSpot($db, $neodb, $SPOT1, $TEST_UPK);
check('rename re-extracts searchtext', tsvHas($db, 'item_hit', $W1, 'SYNCTOK_renamed'));
check('still one row after rename', itemCount($db, $W1) === 1);

// Drop the cross-dataset edge again — the later deleteSingleDataset(DS2)
// E2E expects DS2 to own only its own spot (the class's cascade Cypher is
// not written for spots shared across the deleted and surviving dataset).
$neodb->query("MATCH (d:Dataset {id: $DS2})-[r:HAS_SPOT]->(s:Spot {id: $SPOT1}) DELETE r");
StraboSearchSync::touchSpot($db, $neodb, $SPOT1, $TEST_UPK);

// ===========================================================================
section('3. FIELD — touchImage resolve + stale removal');

$ok = StraboSearchSync::touchImage($db, $neodb, $IMG1, $TEST_UPK);
check('touchImage resolves via parent spot', $ok === true && imageCount($db, $WI1) === 1);

// Stale image row whose node is unreachable → touchImage sweeps it.
$db->query("INSERT INTO strabosearch.image_hit
	(image_id, image_subsystem, image_userpkey, project_id, project_userpkey,
	 project_subsystem, project_ispublic, filename)
	VALUES ('$IMG_STALE', 'field', $TEST_UPK, '$PROJ', $TEST_UPK, 'field', TRUE, 'stale.jpg')");
StraboSearchSync::touchImage($db, $neodb, $IMG_STALE, $TEST_UPK);
check('touchImage removes unreachable image row',
	imageCount($db, "image_subsystem='field' AND image_id='$IMG_STALE'") === 0);

// ===========================================================================
section('4. FIELD — suppression + syncFieldDataset batch');

$neodb->query("MATCH (d:Dataset {id: $DS1}) CREATE (s:Spot {
	id: $SPOT2, userpkey: $TEST_UPK, name: 'spsync Spot Two SYNCTOK_spottwo',
	wkt: 'POINT (-118.26 34.06)', modified_timestamp: 1722400002000
}) CREATE (d)-[:HAS_SPOT]->(s)");

StraboSearchSync::suppressFieldItemTouches();
StraboSearchSync::touchSpot($db, $neodb, $SPOT2, $TEST_UPK);
$W2 = "item_type='spot' AND item_id='$SPOT2' AND item_userpkey=$TEST_UPK";
check('touchSpot no-ops under suppression', itemCount($db, $W2) === 0);
StraboSearchSync::resumeFieldItemTouches();

$neodb->query("MATCH (d:Dataset {id: $DS1}) CREATE (s:Spot {
	id: $SPOT3, userpkey: $TEST_UPK, name: 'spsync Spot Three SYNCTOK_spotthree',
	wkt: 'POINT (-118.27 34.07)', modified_timestamp: 1722400003000
}) CREATE (d)-[:HAS_SPOT]->(s)");

StraboSearchSync::syncFieldDataset($db, $neodb, $DS1, $TEST_UPK);
check('batch sync indexes suppressed spot', itemCount($db, $W2) === 1);
check('batch sync indexes new spot', itemCount($db, "item_id='$SPOT3' AND item_type='spot'") === 1);

StraboSearchSync::removeSpot($db, $SPOT3, $TEST_UPK);
check('removeSpot drops item row', itemCount($db, "item_id='$SPOT3' AND item_type='spot'") === 0);

// ===========================================================================
section('5. FIELD — project meta flip + removeFieldProject');

StraboSearchSync::touchProjectMeta($db, 'field', $PROJ, $TEST_UPK, null, false);
$r = itemRow($db, $W1);
$ir = $db->get_row("SELECT * FROM strabosearch.image_hit WHERE $WI1 LIMIT 1");
check('ispublic flip on item rows', $r && $r->project_ispublic === 'f');
check('ispublic flip on image rows', $ir && $ir->project_ispublic === 'f');
StraboSearchSync::touchProjectMeta($db, 'field', $PROJ, $TEST_UPK, 'spsync renamed project', true);
$r = itemRow($db, $W1);
check('name + ispublic restore', $r && $r->project_ispublic === 't'
	&& $r->project_name === 'spsync renamed project');

// ===========================================================================
section('6. FIELD — class-hook E2E (insertSpot / deleteSingleSpot / deleteSingleDataset)');

$strabo = new StraboSpot($neodb, $TEST_UPK, $db);
require_once '/srv/app/www/includes/UUID.php';
$strabo->setuuid(new UUID());   // deleteSingleDataset → createVersion needs it

$feature = json_encode(array(
	'type' => 'Feature',
	'geometry' => array('type' => 'Point', 'coordinates' => array(-118.30, 34.10)),
	'properties' => array(
		'id' => $SPOT_E2E,
		'name' => 'spsync E2E Spot SYNCTOK_e2espot',
		'modified_timestamp' => 1722410000000,
		'date' => '2026-07-20',
	),
));
$strabo->insertSpot($feature);
$WE = "item_type='spot' AND item_id='$SPOT_E2E' AND item_userpkey=$TEST_UPK";
check('create before dataset link → not yet indexable (0 rows)', itemCount($db, $WE) === 0);

$neodb->query("MATCH (d:Dataset {id: $DS1}), (s:Spot {id: $SPOT_E2E, userpkey: $TEST_UPK})
	CREATE (d)-[:HAS_SPOT]->(s)");
$feature = json_encode(array(
	'type' => 'Feature',
	'geometry' => array('type' => 'Point', 'coordinates' => array(-118.30, 34.10)),
	'properties' => array(
		'id' => $SPOT_E2E,
		'name' => 'spsync E2E Spot updated SYNCTOK_e2eupdated',
		'modified_timestamp' => 1722410001000,
		'date' => '2026-07-20',
	),
));
$strabo->insertSpot($feature);
check('update path hook indexes the spot', itemCount($db, $WE) === 1, 'got ' . itemCount($db, $WE));
check('hook-indexed searchtext token', tsvHas($db, 'item_hit', $WE, 'SYNCTOK_e2eupdated'));

$strabo->deleteSingleSpot($SPOT_E2E);
check('deleteSingleSpot hook removes row', itemCount($db, $WE) === 0);

// Dataset-delete hook: seed a spot in DS2, index it, delete DS2 via the class.
$neodb->query("MATCH (d:Dataset {id: $DS2}) CREATE (s:Spot {
	id: $SPOT_DS2, userpkey: $TEST_UPK, name: 'spsync DS2 spot',
	wkt: 'POINT (-118.31 34.11)', modified_timestamp: 1722400004000
}) CREATE (d)-[:HAS_SPOT]->(s)");
StraboSearchSync::touchSpot($db, $neodb, $SPOT_DS2, $TEST_UPK);
check('DS2 spot indexed pre-delete', itemCount($db, "item_id='$SPOT_DS2' AND item_type='spot'") === 1);
$strabo->deleteSingleDataset($DS2);
check('deleteSingleDataset hook removes DS2 spot row',
	itemCount($db, "item_id='$SPOT_DS2' AND item_type='spot'") === 0);
check('DS1 spots survive dataset delete', itemCount($db, $W1) === 1);

StraboSearchSync::removeFieldProject($db, $PROJ, $TEST_UPK);
check('removeFieldProject drops the whole slice',
	itemCount($db, "project_subsystem='field' AND project_id='$PROJ' AND project_userpkey=$TEST_UPK") === 0
	&& imageCount($db, "image_subsystem='field' AND project_id='$PROJ' AND project_userpkey=$TEST_UPK") === 0);

// ===========================================================================
section('7. MICRO — syncMicroProject slice rebuild + shrink + meta + remove');

$MSTRABO = $PFX . '_microproj';
$db->query("INSERT INTO strabomicro.micro_projectmetadata
	(strabo_id, userpkey, name, ispublic, modifiedtimestamp, notes)
	VALUES ('$MSTRABO', $TEST_UPK, 'spsync Micro Project SYNCTOK_microproj', TRUE, '1722400005000', 'micro notes')");
$mpid = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_datasetmetadata (project_id, strabo_id, name)
	VALUES ($mpid, '{$MSTRABO}_ds', 'mds')");
$mdid = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_samplemetadata
	(dataset_id, strabo_id, label, sampleid, longitude, latitude, samplenotes)
	VALUES ($mdid, '{$MSTRABO}_samp', 'spsync micro sample', 'MS-1', -100.5, 40.5, 'ms notes')");
$msid = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_micrographmetadata
	(sample_id, strabo_id, name, notes, imagetype, width, height)
	VALUES ($msid, '{$MSTRABO}_mg1', 'spsync Micrograph SYNCTOK_micrograph', 'BSE image', 'Backscatter Electron (BSE)', 1024, 768)");
$mgid1 = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_mineralogy (micrograph_id, mineralogymethod)
	VALUES ($mgid1, 'EDS')");
$mlid = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_mineral (mineralogy_id, name, percentage)
	VALUES ($mlid, 'Quartz', '40')");

$ok = StraboSearchSync::syncMicroProject($db, $mpid, $MSTRABO, $TEST_UPK);
check('syncMicroProject returns true', $ok === true);
$WM = "item_type='micrograph' AND item_id='{$MSTRABO}_mg1' AND item_userpkey=$TEST_UPK";
check('micrograph item row', itemCount($db, $WM) === 1);
$r = itemRow($db, $WM);
check('micro minerals facet', $r && strpos((string)$r->minerals, 'Quartz') !== false);
check('micro image_type normalized SEM',
	($ir = $db->get_row("SELECT * FROM strabosearch.image_hit
		WHERE image_subsystem='micro' AND image_id='{$MSTRABO}_mg1' LIMIT 1"))
	&& $ir->image_type === 'micrograph_sem');
check('micro image parent_sample_id', $ir && $ir->parent_sample_id === $MSTRABO . '_samp');

StraboSearchSync::syncMicroProject($db, $mpid, $MSTRABO, $TEST_UPK);
check('micro re-sync idempotent', itemCount($db, $WM) === 1
	&& imageCount($db, "image_subsystem='micro' AND image_id='{$MSTRABO}_mg1'") === 1);

// Shrink: add + sync + delete + sync (stale sweep).
$db->query("INSERT INTO strabomicro.micro_micrographmetadata
	(sample_id, strabo_id, name, notes, imagetype, width, height)
	VALUES ($msid, '{$MSTRABO}_mg2', 'temp micrograph', '', 'thin section scan', 800, 600)");
StraboSearchSync::syncMicroProject($db, $mpid, $MSTRABO, $TEST_UPK);
check('second micrograph indexed', itemCount($db, "item_id='{$MSTRABO}_mg2'") === 1);
$db->query("DELETE FROM strabomicro.micro_micrographmetadata WHERE strabo_id = '{$MSTRABO}_mg2'");
StraboSearchSync::syncMicroProject($db, $mpid, $MSTRABO, $TEST_UPK);
check('stale sweep removes deleted micrograph', itemCount($db, "item_id='{$MSTRABO}_mg2'") === 0
	&& imageCount($db, "image_id='{$MSTRABO}_mg2'") === 0);

StraboSearchSync::touchProjectMeta($db, 'micro', $MSTRABO, $TEST_UPK, null, false);
$r = itemRow($db, $WM);
check('micro ispublic flip', $r && $r->project_ispublic === 'f');

StraboSearchSync::removeMicroProject($db, $MSTRABO, $TEST_UPK);
check('removeMicroProject drops slice',
	itemCount($db, "project_subsystem='micro' AND project_id='$MSTRABO'") === 0
	&& imageCount($db, "image_subsystem='micro' AND project_id='$MSTRABO'") === 0);

// ===========================================================================
section('8. EXP — touchExperiment + rename + removes');

$EUUID = $PFX . '-exp-uuid-1';
$db->query("INSERT INTO straboexp.project (userpkey, uuid, name, notes, ispublic)
	VALUES ($TEST_UPK, '$EUUID', 'spsync Exp Project SYNCTOK_expproj', 'exp notes', TRUE)");
$eppk = (int)$db->insert_id;
$db->query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, json)
	VALUES ($eppk, $TEST_UPK, '{$PFX}_exp1', '{}')");
$epk = (int)$db->insert_id;
$db->query("INSERT INTO straboexp.apparatus (experiment_pkey, userpkey, name, type, description)
	VALUES ($epk, $TEST_UPK, 'spsync rig', 'Triaxial', 'SYNCTOK_apparatus rig')");

$ok = StraboSearchSync::touchExperiment($db, $epk);
check('touchExperiment returns true', $ok === true);
$WE2 = "item_type='experiment' AND item_id='{$PFX}_exp1' AND item_userpkey=$TEST_UPK";
check('experiment row', itemCount($db, $WE2) === 1);
$r = itemRow($db, $WE2);
check('exp apparatus_type facet', $r && $r->apparatus_type === 'Triaxial');
check('exp project context (uuid)', $r && $r->project_id === $EUUID);
check('exp searchtext has project token', tsvHas($db, 'item_hit', $WE2, 'SYNCTOK_expproj'));

$db->query("UPDATE straboexp.project SET name = 'spsync RENAMED SYNCTOK_exprenamed' WHERE pkey = $eppk");
StraboSearchSync::touchExpProject($db, $eppk);
$r = itemRow($db, $WE2);
check('rename refreshes project_name', $r && strpos($r->project_name, 'SYNCTOK_exprenamed') !== false);
check('rename re-extracts searchtext', tsvHas($db, 'item_hit', $WE2, 'SYNCTOK_exprenamed'));

StraboSearchSync::removeExperiment($db, $PFX . '_exp1', $TEST_UPK);
check('removeExperiment drops row', itemCount($db, $WE2) === 0);

StraboSearchSync::touchExperiment($db, $epk);   // re-index for the slice-remove test
check('re-indexed for slice test', itemCount($db, $WE2) === 1);
StraboSearchSync::removeExpProject($db, $EUUID);
check('removeExpProject drops slice',
	itemCount($db, "project_subsystem='exp' AND project_id='$EUUID'") === 0);

// ===========================================================================
section('9. SAMPLES — touchSample fan-out + shrink + spine edit');

// Independent anchors: a directly-seeded Field slice row, a micro pm row,
// an exp project row (all fresh — earlier fixtures were removed above).
$FSPOT = $PFX . '_fanspot';
$db->query("INSERT INTO strabosearch.item_hit
	(item_type, item_id, item_userpkey, project_id, project_userpkey,
	 project_subsystem, project_name, project_ispublic, location)
	VALUES ('spot', '$FSPOT', $TEST_UPK, '{$PFX}_fanproj', $TEST_UPK, 'field',
	        'spsync Fan Field Project', TRUE,
	        ST_SetSRID(ST_MakePoint(-101.0, 41.0), 4326))");
$db->query("INSERT INTO strabomicro.micro_projectmetadata (strabo_id, userpkey, name, ispublic)
	VALUES ('{$PFX}_fanmicro', $TEST_UPK, 'spsync Fan Micro Project', FALSE)");
$fanMpid = (int)$db->insert_id;
$db->query("INSERT INTO straboexp.project (userpkey, uuid, name, notes, ispublic)
	VALUES ($TEST_UPK, '{$PFX}-fan-exp-uuid', 'spsync Fan Exp Project', '', TRUE)");

$S1 = $PFX . '_s1';
$db->query("INSERT INTO strabosamples.samples
	(id, userpkey, name, igsn, description, notes, latitude, longitude,
	 display_sample_type, display_sample_purpose, created_by, modified_by, custom_data)
	VALUES ('$S1', $TEST_UPK, 'spsync Sample One SYNCTOK_sampone', 'SYNC0001',
	        'desc', 'notes', 41.5, -101.5, 'Rock', 'Geochemistry',
	        $TEST_UPK, $TEST_UPK, '{\"drill_run\": \"SYNCTOK_custom\"}')");
$db->query("INSERT INTO strabosamples.sample_subsystem_links
	(sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
	VALUES ('$S1', $TEST_UPK, 'field', '$FSPOT', $TEST_UPK, '{\"rich\": false}'),
	       ('$S1', $TEST_UPK, 'micro', 'mref', $TEST_UPK, '{\"project_strabo_id\": \"{$PFX}_fanmicro\"}'),
	       ('$S1', $TEST_UPK, 'experimental', 'eref', $TEST_UPK, '{\"project_uuid\": \"{$PFX}-fan-exp-uuid\"}')");

$ok = StraboSearchSync::touchSample($db, $S1, $TEST_UPK);
check('touchSample returns true', $ok === true);
$WS = "item_type='sample' AND item_id='$S1' AND item_userpkey=$TEST_UPK";
check('3-way fan-out', itemCount($db, $WS) === 3, 'got ' . itemCount($db, $WS));
check('experimental → exp translation',
	itemCount($db, "$WS AND project_subsystem='exp'") === 1);
check('sample searchtext custom_data token', tsvHas($db, 'item_hit',
	"$WS AND project_subsystem='field'", 'SYNCTOK_custom'));

$db->query("UPDATE strabosamples.samples SET name = 'spsync EDITED SYNCTOK_sampedit' WHERE id='$S1' AND userpkey=$TEST_UPK");
StraboSearchSync::touchSample($db, $S1, $TEST_UPK);
check('spine edit refreshes all fan-out names',
	(int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit
		WHERE $WS AND sample_name LIKE '%SYNCTOK_sampedit%'") === 3);

$db->query("DELETE FROM strabosamples.sample_subsystem_links
	WHERE sample_id='$S1' AND sample_userpkey=$TEST_UPK AND subsystem='micro'");
StraboSearchSync::touchSample($db, $S1, $TEST_UPK);
check('unlink micro shrinks fan-out to 2', itemCount($db, $WS) === 2,
	'got ' . itemCount($db, $WS));
check('micro-hosted row is the one gone',
	itemCount($db, "$WS AND project_subsystem='micro'") === 0);

// ===========================================================================
section('10. SAMPLES — service-hook E2E (upsertSample / removeSubsystemSample / deleteSample)');

$S2 = $PFX . '_s2';
$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($TEST_UPK);
$svc->upsertSample('field', $S2, $TEST_UPK,
	array('name' => 'spsync Service Sample SYNCTOK_svcsample'),
	array('raw' => 'fixture'),
	array('reference_id' => $FSPOT, 'reference_userpkey' => $TEST_UPK,
	      'reference_metadata' => array('project_id' => $PFX . '_fanproj')),
	array(), array());
$WS2 = "item_type='sample' AND item_id='$S2' AND item_userpkey=$TEST_UPK";
check('upsertSample hook indexes without manual touch', itemCount($db, $WS2) === 1,
	'got ' . itemCount($db, $WS2));

$svc->removeSubsystemSample('field', $S2, $TEST_UPK);
check('removeSubsystemSample hook clears fan-out', itemCount($db, $WS2) === 0);

// deleteSample on S1 (still has 2 fan-out rows).
$svc->deleteSample($S1, $TEST_UPK);
check('deleteSample hook removes all fan-out rows', itemCount($db, $WS) === 0);

// ===========================================================================
section('11. Hardening — never-throw + no leaked advisory locks');

$threw = false;
try {
	StraboSearchSync::touchSpot($db, $neodb, "bogus'id", -1);
	StraboSearchSync::touchSample($db, "no'such", -1);
	StraboSearchSync::touchExperiment($db, -99);
	StraboSearchSync::syncMicroProject($db, -99, 'nope', -1);
} catch (Throwable $e) {
	$threw = true;
}
check('garbage input never throws', $threw === false);

// A server-side Cypher failure kills the GraphAware Bolt connection (the
// next query blocks forever). Verify reconnect() actually recovers it —
// this is the safety net the sync catch paths rely on.
try { $neodb->query("THIS IS NOT CYPHER((("); } catch (Throwable $e) {}
$neodb->reconnect();
check('bolt reconnect() recovers a poisoned connection',
	(string)$neodb->get_var("RETURN 1") === '1');

$locks = (int)$db->get_var("SELECT count(*) FROM pg_locks
	WHERE locktype = 'advisory' AND pid = pg_backend_pid()");
check('no leaked advisory locks on this connection', $locks === 0, "held: $locks");

// ===========================================================================
section('12. Cleanup');

sync_cleanup($db, $neodb, $TEST_UPK, $PFX);
echo "  cleared fixtures for upk $TEST_UPK\n";

echo PHP_EOL;
if ($failures) {
	echo 'FAILURES (' . count($failures) . '):' . PHP_EOL;
	foreach ($failures as $f) echo '  - ' . $f . PHP_EOL;
	exit(1);
}
echo 'ALL CHECKS PASSED.' . PHP_EOL;
exit(0);
?>
