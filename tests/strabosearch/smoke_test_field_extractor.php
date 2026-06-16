<?php
/**
 * File: smoke_test_field_extractor.php
 * Description: Permanent smoke suite for the Field extractor — verifies
 *              that searchdb/extractors/field.php correctly populates
 *              strabosearch.item_hit rows for every column the §4 search
 *              model exposes for Field.
 *
 *              Hermetic: seeds Neo4j with a controlled set of Spots under
 *              an isolated userpkey (94501), runs the extractor with
 *              --source-userpkey=94501 --apply, queries item_hit for the
 *              expected per-spot column values, and tears down.
 *
 *              Scenarios covered:
 *                A. Public project, point spot, full orientation array →
 *                   item_hit row with location, has_orientation=TRUE,
 *                   orientation_strike/dip arrays populated.
 *                B. Private project, polygon spot, igneous-plutonic tag →
 *                   project_ispublic=FALSE, location is the polygon
 *                   centroid, rock_types contains 'igneous:plutonic'.
 *                C. Metamorphic-greenschist tag → met_facies contains
 *                   'greenschist'.
 *                D. Spot with json_trace structural data → trace_types
 *                   contains the trace_feature_type, has_orientation=FALSE.
 *                E. Spot with json_samples present → has_samples=TRUE.
 *                F. Spot with HAS_IMAGE relationship → has_images=TRUE.
 *                G. Spot with no extension data → all extension columns
 *                   NULL, all has_* flags FALSE.
 *                H. searchtext_tsv contains a unique fixture token from
 *                   spot.name (proves U1 keyword indexing wires up).
 *                I. sync_state NOT updated on partial run (--source-userpkey).
 *                J. Atomic swap idempotency: rerunning the extractor a
 *                   second time leaves the same row set (no duplicates,
 *                   no drift).
 *
 *              Self-contained: cleans up before and after. Exits non-zero
 *              on any failure so it can gate CI.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_field_extractor.php
 *
 * @package    StraboSearch Phase 2 extractors
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$TEST_UPK    = 94501;
$PREFIX      = 'spsx94501';  // strabosearch-eXtractor + isolation upk
$PROJ_PUB    = $PREFIX . '_proj_pub';
$PROJ_PRIV   = $PREFIX . '_proj_priv';
$DSET        = $PREFIX . '_dataset';
$DSET_PRIV   = $PREFIX . '_dataset_priv';

// Second user — owns a Project node with the SAME id as $PROJ_PUB to
// exercise the User-anchored "ids are not unique" guarantee (Scenario X).
$DECOY_UPK   = 94502;
$DECOY_PFX   = 'spsx94502';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	$mark = $cond ? '  PASS' : '  FAIL';
	echo $mark . '  ' . $label . ($detail !== '' ? '  -- ' . $detail : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}

function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . '== ' . $t . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

// ===========================================================================
section('0. Cleanup any leftover fixtures');

// Drop ALL fixture nodes + the slice in item_hit before seeding.
// Match BOTH prefixes (94501 + decoy 94502) and a User node by userpkey.
$neodb->query("MATCH (n) WHERE n.id =~ '$PREFIX.*' OR n.id =~ '$DECOY_PFX.*' DETACH DELETE n");
$neodb->query("MATCH (u:User) WHERE u.userpkey IN [$TEST_UPK, $DECOY_UPK] DETACH DELETE u");
$db->query("DELETE FROM strabosearch.item_hit WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK)");
$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_field");
// Project.ispublic lives in PG (Neo4j Project.ispublic is NULL everywhere).
// Clear + reseed two PG rows so the extractor's per-project lookup finds
// the public/private signal we're testing. The PG project FK requires the
// user_pkey to exist in users — seed two fixture users.
$db->query("DELETE FROM project WHERE strabo_project_id IN ('$PROJ_PUB', '$PROJ_PRIV')");
$db->query("DELETE FROM users WHERE pkey IN ($TEST_UPK, $DECOY_UPK)");
$db->query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active)
	VALUES ($TEST_UPK, 'spsx', 'fixture',
	        'spsx-fixture-$TEST_UPK@test.strabospot.org',
	        'x', 'x', false),
	       ($DECOY_UPK, 'spsx', 'decoy',
	        'spsx-decoy-$DECOY_UPK@test.strabospot.org',
	        'x', 'x', false)");
echo '  cleared fixture nodes + item_hit slice + pg fixtures for upk '
	. $TEST_UPK . ' and decoy ' . $DECOY_UPK . PHP_EOL;

// ===========================================================================
section('1. Seed Neo4j fixtures');

// Two projects (public + private), each with one dataset and various spots.
// Spot ids are deliberately short numeric (Neo4j id properties are
// historically string-ish; the extractor coerces).

// Fixture User node — the extractor anchors walks through
// (:User)-[:HAS_PROJECT]->(:Project). Without this node + HAS_PROJECT
// edges the extractor would return zero spots.
$neodb->query("CREATE (u:User {
	userpkey: $TEST_UPK,
	email: 'spsx-fixture-$TEST_UPK@test.strabospot.org',
	firstname: 'spsx',
	lastname: 'fixture'
})");

// Public project (Neo4j node + PG row — extractor reads ispublic from PG)
$neodb->query("CREATE (p:Project {
	id: '${PREFIX}_proj_pub',
	userpkey: $TEST_UPK,
	name: 'spsx Public Test Project'
})");
$neodb->query("MATCH (u:User {userpkey: $TEST_UPK}),
                     (p:Project {id: '${PREFIX}_proj_pub'})
               CREATE (u)-[:HAS_PROJECT]->(p)");
$db->query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic)
	VALUES ($TEST_UPK, 'spsx Public Test Project', '$PROJ_PUB', TRUE)");

// Private project
$neodb->query("CREATE (p:Project {
	id: '${PREFIX}_proj_priv',
	userpkey: $TEST_UPK,
	name: 'spsx Private Test Project'
})");
$neodb->query("MATCH (u:User {userpkey: $TEST_UPK}),
                     (p:Project {id: '${PREFIX}_proj_priv'})
               CREATE (u)-[:HAS_PROJECT]->(p)");
$db->query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic)
	VALUES ($TEST_UPK, 'spsx Private Test Project', '$PROJ_PRIV', FALSE)");

// Datasets
$neodb->query("MATCH (p:Project {id: '${PREFIX}_proj_pub'})
	CREATE (d:Dataset {id: '${PREFIX}_dataset', userpkey: $TEST_UPK, name: 'spsx Dataset Alpha'})
	CREATE (p)-[:HAS_DATASET]->(d)");
$neodb->query("MATCH (p:Project {id: '${PREFIX}_proj_priv'})
	CREATE (d:Dataset {id: '${PREFIX}_dataset_priv', userpkey: $TEST_UPK, name: 'spsx Dataset Beta'})
	CREATE (p)-[:HAS_DATASET]->(d)");

// SCENARIO A — public project, point spot, full orientation
$jodA = json_encode(array(
	array('type' => 'planar_orientation', 'feature_type' => 'bedding',
	      'strike' => 45.5, 'dip' => 30, 'quality' => 'good'),
	array('type' => 'linear_orientation', 'feature_type' => 'lineation',
	      'trend' => 120, 'plunge' => 15, 'quality' => 'fair'),
));
$neodb->query("MATCH (d:Dataset {id: '${PREFIX}_dataset'})
	CREATE (s:Spot {
		id: '${PREFIX}_spot_A',
		userpkey: $TEST_UPK,
		name: 'spsx Spot Alpha — UNIQUEKEYWORD_alpha',
		notes: 'a fixture spot for orientation testing',
		wkt: 'POINT(-118.5 34.2)',
		date: '2024-06-01T12:00:00-07:00',
		modified_timestamp: 1717257600,
		json_orientation_data: '$jodA'
	})
	CREATE (d)-[:HAS_SPOT]->(s)");

// SCENARIO B — private project, polygon spot, igneous-plutonic tag
$neodb->query("MATCH (d:Dataset {id: '${PREFIX}_dataset_priv'})
	CREATE (s:Spot {
		id: '${PREFIX}_spot_B',
		userpkey: $TEST_UPK,
		name: 'spsx Spot Beta',
		wkt: 'POLYGON((-118.6 34.3, -118.5 34.3, -118.5 34.4, -118.6 34.4, -118.6 34.3))',
		date: '2024-06-02T08:00:00-07:00',
		modified_timestamp: 1717344000
	})
	CREATE (d)-[:HAS_SPOT]->(s)");
$neodb->query("CREATE (t:Tag {
	id: '${PREFIX}_tag_igneous',
	userpkey: $TEST_UPK,
	type: 'geologic_unit',
	name: 'spsx Granite Unit',
	rock_type: 'igneous',
	igneous_rock_class: 'plutonic',
	plutonic_rock_types: 'granite'
})");
$neodb->query("MATCH (s:Spot {id: '${PREFIX}_spot_B'}), (t:Tag {id: '${PREFIX}_tag_igneous'})
	CREATE (s)-[:IS_TAGGED]->(t)");

// SCENARIO C — metamorphic-greenschist tag → met_facies
$neodb->query("MATCH (d:Dataset {id: '${PREFIX}_dataset'})
	CREATE (s:Spot {
		id: '${PREFIX}_spot_C',
		userpkey: $TEST_UPK,
		name: 'spsx Spot Gamma',
		wkt: 'POINT(-118.4 34.1)',
		date: '2024-06-03T10:00:00-07:00',
		modified_timestamp: 1717430400
	})
	CREATE (d)-[:HAS_SPOT]->(s)");
$neodb->query("CREATE (t:Tag {
	id: '${PREFIX}_tag_metamorphic',
	userpkey: $TEST_UPK,
	type: 'geologic_unit',
	name: 'spsx Schist Unit',
	rock_type: 'metamorphic',
	metamorphic_rock_types: 'schist',
	metamorphic_grade: 'greenschist'
})");
$neodb->query("MATCH (s:Spot {id: '${PREFIX}_spot_C'}), (t:Tag {id: '${PREFIX}_tag_metamorphic'})
	CREATE (s)-[:IS_TAGGED]->(t)");

// SCENARIO D — json_trace
$jtrD = json_encode(array(
	array('trace_feature_type' => 'fault'),
	array('trace_feature_type' => 'contact'),
));
$neodb->query("MATCH (d:Dataset {id: '${PREFIX}_dataset'})
	CREATE (s:Spot {
		id: '${PREFIX}_spot_D',
		userpkey: $TEST_UPK,
		name: 'spsx Spot Delta',
		wkt: 'LINESTRING(-118.3 34.0, -118.3 34.1)',
		modified_timestamp: 1717516800,
		json_trace: '$jtrD'
	})
	CREATE (d)-[:HAS_SPOT]->(s)");

// SCENARIO E — json_samples present
$jsmE = json_encode(array(array('id' => 'samp1', 'label' => 'SPSX-E-S1')));
$neodb->query("MATCH (d:Dataset {id: '${PREFIX}_dataset'})
	CREATE (s:Spot {
		id: '${PREFIX}_spot_E',
		userpkey: $TEST_UPK,
		name: 'spsx Spot Epsilon',
		wkt: 'POINT(-118.2 33.9)',
		modified_timestamp: 1717603200,
		json_samples: '$jsmE'
	})
	CREATE (d)-[:HAS_SPOT]->(s)");

// SCENARIO F — HAS_IMAGE relationship
$neodb->query("MATCH (d:Dataset {id: '${PREFIX}_dataset'})
	CREATE (s:Spot {
		id: '${PREFIX}_spot_F',
		userpkey: $TEST_UPK,
		name: 'spsx Spot Zeta',
		wkt: 'POINT(-118.1 33.8)',
		modified_timestamp: 1717689600
	})
	CREATE (d)-[:HAS_SPOT]->(s)
	CREATE (i:Image {
		id: '${PREFIX}_img_F1',
		userpkey: $TEST_UPK,
		filename: 'spsx_fixture_image.jpg',
		image_type: 'photo'
	})
	CREATE (s)-[:HAS_IMAGE]->(i)");

// SCENARIO G — bare spot, no extensions
$neodb->query("MATCH (d:Dataset {id: '${PREFIX}_dataset'})
	CREATE (s:Spot {
		id: '${PREFIX}_spot_G',
		userpkey: $TEST_UPK,
		name: 'spsx Spot Eta',
		wkt: 'POINT(-118.0 33.7)',
		modified_timestamp: 1717776000
	})
	CREATE (d)-[:HAS_SPOT]->(s)");

// SCENARIO X — decoy user with SAME Project id as $PROJ_PUB.
// Exercises the User-anchored "ids are not unique" guarantee. When we
// extract for $TEST_UPK, the decoy's spot must NOT appear in $TEST_UPK's
// item_hit rows even though both Projects share the id '$PROJ_PUB'.
$neodb->query("CREATE (u:User {
	userpkey: $DECOY_UPK,
	email: 'spsx-decoy-$DECOY_UPK@test.strabospot.org',
	firstname: 'spsx', lastname: 'decoy'
})");
$neodb->query("CREATE (p:Project {
	id: '${PROJ_PUB}', userpkey: $DECOY_UPK,
	name: 'spsx DECOY Project (same id as PUB)'
})");
$neodb->query("MATCH (u:User {userpkey: $DECOY_UPK}),
                     (p:Project {id: '${PROJ_PUB}', userpkey: $DECOY_UPK})
               CREATE (u)-[:HAS_PROJECT]->(p)");
$neodb->query("MATCH (p:Project {id: '${PROJ_PUB}', userpkey: $DECOY_UPK})
	CREATE (d:Dataset {
		id: '${PREFIX}_dataset', userpkey: $DECOY_UPK,
		name: 'spsx DECOY Dataset (same id as PRIMARY)'
	})
	CREATE (p)-[:HAS_DATASET]->(d)");
$neodb->query("MATCH (d:Dataset {id: '${PREFIX}_dataset', userpkey: $DECOY_UPK})
	CREATE (s:Spot {
		id: '${PREFIX}_spot_DECOY', userpkey: $DECOY_UPK,
		name: 'spsx DECOY Spot — DECOYKEYWORD_should_not_leak',
		wkt: 'POINT(-100.0 30.0)',
		modified_timestamp: 1717862400
	})
	CREATE (d)-[:HAS_SPOT]->(s)");

echo '  seeded 7 primary spots + 1 decoy under same-id Project/Dataset' . PHP_EOL;

// ===========================================================================
section('2. Run extractor');

// Snapshot sync_state.field BEFORE the first extractor invocation so
// section 12's "partial run doesn't bump sync_state" check is honest
// regardless of any prior full-run state on dev.
$syncBefore = $db->get_var("SELECT last_full_backfill FROM strabosearch.sync_state WHERE source='field'");

$cmd = "php /srv/app/www/searchdb/extractors/field.php --source-userpkey=$TEST_UPK --apply 2>&1";
$out = shell_exec($cmd);
$ok = (strpos($out, 'SWAP FAILED') === false && strpos($out, 'FAIL row') === false);
check('extractor exits clean', $ok, $ok ? '' : substr($out, -400));

// ===========================================================================
section('3. Row presence + project context');

$rowCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'field'"
);
check('7 rows landed for the fixture user', $rowCount === 7, "got $rowCount");

$pubCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_ispublic = TRUE"
);
$privCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_ispublic = FALSE"
);
check('public project rows: 6', $pubCount === 6, "got $pubCount");
check('private project rows: 1', $privCount === 1, "got $privCount");

$pname = $db->get_var(
	"SELECT project_name FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_ispublic = TRUE LIMIT 1"
);
check('project_name denormalized', $pname === 'spsx Public Test Project', "got '$pname'");

$itype = $db->get_var(
	"SELECT distinct item_type FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK"
);
check("item_type = 'spot'", $itype === 'spot', "got '$itype'");

// ===========================================================================
section('4. Scenario A — orientation extraction');

$rowA = $db->get_row(
	"SELECT has_orientation, orientation_strike, orientation_dip,
	        orientation_trend, orientation_plunge, orientation_features,
	        ST_AsText(location) AS loc
	 FROM strabosearch.item_hit WHERE item_id = '${PREFIX}_spot_A'"
);
check('A: row found', $rowA !== null);
if ($rowA) {
	check('A: has_orientation = TRUE', $rowA->has_orientation === 't');
	check('A: orientation_strike contains 45.5',
		strpos((string)$rowA->orientation_strike, '45.5') !== false,
		'got ' . $rowA->orientation_strike);
	check('A: orientation_dip contains 30',
		strpos((string)$rowA->orientation_dip, '30') !== false,
		'got ' . $rowA->orientation_dip);
	check('A: orientation_trend contains 120',
		strpos((string)$rowA->orientation_trend, '120') !== false,
		'got ' . $rowA->orientation_trend);
	check('A: orientation_features contains bedding',
		strpos((string)$rowA->orientation_features, 'bedding') !== false,
		'got ' . $rowA->orientation_features);
	check('A: location is the POINT', strpos((string)$rowA->loc, 'POINT') !== false,
		'got ' . $rowA->loc);
}

// ===========================================================================
section('5. Scenario B — igneous:plutonic rock_types + polygon centroid');

$rowB = $db->get_row(
	"SELECT rock_types, ST_AsText(location) AS loc, project_ispublic
	 FROM strabosearch.item_hit WHERE item_id = '${PREFIX}_spot_B'"
);
check('B: row found', $rowB !== null);
if ($rowB) {
	check('B: rock_types contains igneous:plutonic:granite',
		strpos((string)$rowB->rock_types, 'igneous:plutonic:granite') !== false,
		'got ' . $rowB->rock_types);
	check('B: project_ispublic = FALSE', $rowB->project_ispublic === 'f');
	// The polygon centroid should be roughly (-118.55, 34.35)
	check('B: location is a POINT (polygon centroid)',
		strpos((string)$rowB->loc, 'POINT') !== false, 'got ' . $rowB->loc);
}

// ===========================================================================
section('6. Scenario C — met_facies extraction');

$rowC = $db->get_row(
	"SELECT rock_types, met_facies FROM strabosearch.item_hit
	 WHERE item_id = '${PREFIX}_spot_C'"
);
check('C: row found', $rowC !== null);
if ($rowC) {
	check('C: rock_types contains metamorphic:schist',
		strpos((string)$rowC->rock_types, 'metamorphic:schist') !== false,
		'got ' . $rowC->rock_types);
	check('C: met_facies contains greenschist',
		strpos((string)$rowC->met_facies, 'greenschist') !== false,
		'got ' . $rowC->met_facies);
}

// ===========================================================================
section('7. Scenario D — trace_types extraction');

$rowD = $db->get_row(
	"SELECT trace_types, has_orientation FROM strabosearch.item_hit
	 WHERE item_id = '${PREFIX}_spot_D'"
);
check('D: row found', $rowD !== null);
if ($rowD) {
	check('D: trace_types contains fault',
		strpos((string)$rowD->trace_types, 'fault') !== false,
		'got ' . $rowD->trace_types);
	check('D: trace_types contains contact',
		strpos((string)$rowD->trace_types, 'contact') !== false,
		'got ' . $rowD->trace_types);
	check('D: has_orientation = FALSE', $rowD->has_orientation === 'f');
}

// ===========================================================================
section('8. Scenario E — has_samples');

$rowE = $db->get_row(
	"SELECT has_samples FROM strabosearch.item_hit
	 WHERE item_id = '${PREFIX}_spot_E'"
);
check('E: row found', $rowE !== null);
if ($rowE) check('E: has_samples = TRUE', $rowE->has_samples === 't');

// ===========================================================================
section('9. Scenario F — has_images via HAS_IMAGE');

$rowF = $db->get_row(
	"SELECT has_images FROM strabosearch.item_hit
	 WHERE item_id = '${PREFIX}_spot_F'"
);
check('F: row found', $rowF !== null);
if ($rowF) check('F: has_images = TRUE', $rowF->has_images === 't');

// ===========================================================================
section('10. Scenario G — bare spot has all has_* flags FALSE');

$rowG = $db->get_row(
	"SELECT has_orientation, has_samples, has_images, has_microstructure,
	        has_strat, orientation_strike, rock_types, trace_types
	 FROM strabosearch.item_hit WHERE item_id = '${PREFIX}_spot_G'"
);
check('G: row found', $rowG !== null);
if ($rowG) {
	check('G: has_orientation FALSE', $rowG->has_orientation === 'f');
	check('G: has_samples FALSE',     $rowG->has_samples === 'f');
	check('G: has_images FALSE',      $rowG->has_images === 'f');
	check('G: has_microstructure FALSE', $rowG->has_microstructure === 'f');
	check('G: has_strat FALSE',       $rowG->has_strat === 'f');
	check('G: orientation_strike NULL', $rowG->orientation_strike === null);
	check('G: rock_types NULL',         $rowG->rock_types === null);
	check('G: trace_types NULL',        $rowG->trace_types === null);
}

// ===========================================================================
section('11. searchtext_tsv U1 keyword search');

$hit = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK
	   AND searchtext_tsv @@ to_tsquery('uniquekeyword_alpha')"
);
check('searchtext_tsv finds the unique spot.name token (1 hit)', $hit === 1, "got $hit");

// ===========================================================================
section('12. sync_state NOT updated on partial run');

$syncAfter = $db->get_var("SELECT last_full_backfill FROM strabosearch.sync_state WHERE source='field'");
check('sync_state.field last_full_backfill unchanged after --source-userpkey',
	$syncBefore === $syncAfter,
	'before=' . var_export($syncBefore, true) . ' after=' . var_export($syncAfter, true));

// ===========================================================================
section('13. Idempotency — re-run produces same row set');

$out2 = shell_exec($cmd);
$rowCount2 = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'field'"
);
check('idempotent re-run: still 7 rows', $rowCount2 === 7, "got $rowCount2");

// Tag survival across re-runs (re-run rebuilds staging from scratch).
$rowB2 = $db->get_row(
	"SELECT rock_types FROM strabosearch.item_hit WHERE item_id = '${PREFIX}_spot_B'"
);
check('idempotent: spot_B still has igneous:plutonic:granite',
	$rowB2 && strpos((string)$rowB2->rock_types, 'igneous:plutonic:granite') !== false);

// ===========================================================================
section('14. Scenario X — same-id decoy must NOT cross-contaminate');

// $TEST_UPK extraction should produce 7 rows; the decoy's spot
// (id ${PREFIX}_spot_DECOY, owner $DECOY_UPK) must NOT appear under
// $TEST_UPK's project_userpkey, AND the decoy's keyword must not
// land in $TEST_UPK's searchtext_tsv even though the decoy Project,
// Dataset, and Spot share an id with primary fixtures.

$decoyLeakAsTestUserItem = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND item_id = '${PREFIX}_spot_DECOY'"
);
check('decoy spot NOT under TEST_UPK rows', $decoyLeakAsTestUserItem === 0,
	"got $decoyLeakAsTestUserItem");

$decoyKeywordLeak = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK
	   AND searchtext_tsv @@ to_tsquery('decoykeyword_should_not_leak')"
);
check('decoy keyword NOT in TEST_UPK searchtext_tsv', $decoyKeywordLeak === 0,
	"got $decoyKeywordLeak");

$testUserRowCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK"
);
check('TEST_UPK row count unaffected by decoy (still 7)',
	$testUserRowCount === 7, "got $testUserRowCount");

// ===========================================================================
section('15. Cleanup');

$neodb->query("MATCH (n) WHERE n.id =~ '$PREFIX.*' OR n.id =~ '$DECOY_PFX.*' DETACH DELETE n");
$neodb->query("MATCH (u:User) WHERE u.userpkey IN [$TEST_UPK, $DECOY_UPK] DETACH DELETE u");
$db->query("DELETE FROM strabosearch.item_hit WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK)");
$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_field");
$db->query("DELETE FROM project WHERE strabo_project_id IN ('$PROJ_PUB', '$PROJ_PRIV')");
$db->query("DELETE FROM users WHERE pkey IN ($TEST_UPK, $DECOY_UPK)");
echo '  cleared fixture nodes + slice + staging + pg fixtures' . PHP_EOL;

// ===========================================================================
echo PHP_EOL;
if ($failures) {
	echo count($failures) . ' FAIL(s):' . PHP_EOL;
	foreach ($failures as $f) echo '  - ' . $f . PHP_EOL;
	exit(1);
}
echo 'ALL CHECKS PASSED.' . PHP_EOL;
exit(0);
?>
