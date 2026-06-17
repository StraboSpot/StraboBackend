<?php
/**
 * File: smoke_test_field_images.php
 * Description: Permanent smoke suite for the Field image extractor —
 *              verifies that searchdb/extractors/field_images.php
 *              correctly populates strabosearch.image_hit rows for every
 *              column the §4 search model exposes for Field images.
 *
 *              Hermetic: seeds Neo4j with a controlled set of Spots + Images
 *              under an isolated userpkey (94503), runs the extractor with
 *              --source-userpkey=94503 --apply, queries image_hit for the
 *              expected per-image column values, and tears down.
 *
 *              Scenarios covered:
 *                A. Public-project image with full metadata (photo type,
 *                   title + caption + filename + annotated="1"). All
 *                   image-native columns populated; image_type unified
 *                   to 'photo'; imagetext_tsv carries both title and
 *                   caption tokens.
 *                B. Thin-section image, vocab mapping ('thin_section' →
 *                   'thin_section'). Parent spot carries orientation +
 *                   igneous-plutonic tag → §6 Q4a + Q4b inheritance
 *                   verified: image_hit row has orientation_strike/dip
 *                   arrays + rock_types from the parent spot.
 *                C. Tail image_type ('strat_section') normalizes to
 *                   'other' per §4.5; vocab_image_type table records the
 *                   raw → unified mapping.
 *                D. annotated="" coerces to FALSE; annotated="1" coerces
 *                   to TRUE; annotated absent coerces to NULL.
 *                E. Image with no filename is SKIPPED (§5.5 servability
 *                   gate — no row in image_hit).
 *                F. Private project image inherits ispublic=FALSE.
 *                G. Polygon parent spot → location is the centroid POINT.
 *                H. Spot with json_trace inherits trace_types into the
 *                   image row (Q4b inheritance for trace).
 *                I. imagetext_tsv U1 keyword search finds unique fixture
 *                   tokens from BOTH title and caption.
 *                J. vocab_image_type rows upserted: 'photo'→'photo',
 *                   'sketch'→'sketch', 'thin_section'→'thin_section',
 *                   'strat_section'→'other', 'sample'→'sample', all
 *                   under subsystem='field'.
 *                K. Atomic swap idempotency: rerunning the extractor a
 *                   second time leaves the same row set (no duplicates,
 *                   no drift).
 *                L. Same-id decoy: a decoy user owns an :Image with the
 *                   SAME id as a primary fixture image; under TEST_UPK
 *                   extraction the decoy must NOT appear in TEST_UPK's
 *                   image_hit rows (User-anchored walk guarantee).
 *                M. sync_state NOT updated on --source-userpkey partial
 *                   run.
 *
 *              Self-contained: cleans up before and after. Exits non-zero
 *              on any failure so it can gate CI.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_field_images.php
 *
 * @package    StraboSearch Phase 2 extractors
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$TEST_UPK    = 94503;
$PREFIX      = 'spsxi94503';   // strabosearch-eXtractor-Images + isolation upk
$PROJ_PUB    = $PREFIX . '_proj_pub';
$PROJ_PRIV   = $PREFIX . '_proj_priv';
$DSET_PUB    = $PREFIX . '_dataset_pub';
$DSET_PRIV   = $PREFIX . '_dataset_priv';

$DECOY_UPK   = 94504;
$DECOY_PFX   = 'spsxi94504';

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

$neodb->query("MATCH (n) WHERE n.id =~ '$PREFIX.*' OR n.id =~ '$DECOY_PFX.*' DETACH DELETE n");
$neodb->query("MATCH (u:User) WHERE u.userpkey IN [$TEST_UPK, $DECOY_UPK] DETACH DELETE u");
$db->query("DELETE FROM strabosearch.image_hit WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK)");
$db->query("DROP TABLE IF EXISTS strabosearch.image_hit_staging_field");
$db->query("DELETE FROM project WHERE strabo_project_id IN ('$PROJ_PUB', '$PROJ_PRIV')");
$db->query("DELETE FROM users WHERE pkey IN ($TEST_UPK, $DECOY_UPK)");
$db->query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active)
	VALUES ($TEST_UPK, 'spsxi', 'fixture',
	        'spsxi-fixture-$TEST_UPK@test.strabospot.org',
	        'x', 'x', false),
	       ($DECOY_UPK, 'spsxi', 'decoy',
	        'spsxi-decoy-$DECOY_UPK@test.strabospot.org',
	        'x', 'x', false)");
echo '  cleared fixture nodes + image_hit slice + pg fixtures for upk '
	. $TEST_UPK . ' and decoy ' . $DECOY_UPK . PHP_EOL;

// ===========================================================================
section('1. Seed Neo4j fixtures');

// Fixture User node — image extractor anchors walks through
// (:User)-[:HAS_PROJECT]->(:Project), same as field.php.
$neodb->query("CREATE (u:User {
	userpkey: $TEST_UPK,
	email: 'spsxi-fixture-$TEST_UPK@test.strabospot.org',
	firstname: 'spsxi',
	lastname: 'fixture'
})");

// Public + private projects
$neodb->query("CREATE (p:Project {
	id: '${PROJ_PUB}', userpkey: $TEST_UPK,
	desc_project_name: 'spsxi Public Image Project'
})");
$neodb->query("MATCH (u:User {userpkey: $TEST_UPK}),
                     (p:Project {id: '${PROJ_PUB}'})
               CREATE (u)-[:HAS_PROJECT]->(p)");
$db->query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic)
	VALUES ($TEST_UPK, 'spsxi Public Image Project', '$PROJ_PUB', TRUE)");

$neodb->query("CREATE (p:Project {
	id: '${PROJ_PRIV}', userpkey: $TEST_UPK,
	desc_project_name: 'spsxi Private Image Project'
})");
$neodb->query("MATCH (u:User {userpkey: $TEST_UPK}),
                     (p:Project {id: '${PROJ_PRIV}'})
               CREATE (u)-[:HAS_PROJECT]->(p)");
$db->query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic)
	VALUES ($TEST_UPK, 'spsxi Private Image Project', '$PROJ_PRIV', FALSE)");

// Datasets
$neodb->query("MATCH (p:Project {id: '${PROJ_PUB}'})
	CREATE (d:Dataset {id: '${DSET_PUB}', userpkey: $TEST_UPK, name: 'spsxi Pub Dataset'})
	CREATE (p)-[:HAS_DATASET]->(d)");
$neodb->query("MATCH (p:Project {id: '${PROJ_PRIV}'})
	CREATE (d:Dataset {id: '${DSET_PRIV}', userpkey: $TEST_UPK, name: 'spsxi Priv Dataset'})
	CREATE (p)-[:HAS_DATASET]->(d)");

// ---------------------------------------------------------------------------
// SCENARIO A — public photo with full metadata + annotated="1"
$neodb->query("MATCH (d:Dataset {id: '${DSET_PUB}'})
	CREATE (s:Spot {
		id: '${PREFIX}_spotA',
		userpkey: $TEST_UPK,
		name: 'spsxi Spot A',
		wkt: 'POINT(-118.5 34.2)',
		date: '2024-06-01T12:00:00-07:00',
		modified_timestamp: 1717257600
	})
	CREATE (d)-[:HAS_SPOT]->(s)
	CREATE (i:Image {
		id: '${PREFIX}_imgA1',
		userpkey: $TEST_UPK,
		filename: '${PREFIX}_imgA1.jpg',
		image_type: 'photo',
		title: 'IMGAUNIQUEKEY_titletoken',
		caption: 'IMGAUNIQUEKEY_captiontoken granite outcrop',
		annotated: '1',
		modified_timestamp: 1717257700
	})
	CREATE (s)-[:HAS_IMAGE]->(i)");

// ---------------------------------------------------------------------------
// SCENARIO B — thin_section image whose parent spot has orientation + igneous tag.
// Verifies §6 Q4a (universal-core inherits) + Q4b (Field-extension inherits).
$jodB = json_encode(array(
	array('type' => 'planar_orientation', 'feature_type' => 'bedding',
	      'strike' => 88.5, 'dip' => 72, 'quality' => 'good'),
));
$neodb->query("MATCH (d:Dataset {id: '${DSET_PUB}'})
	CREATE (s:Spot {
		id: '${PREFIX}_spotB',
		userpkey: $TEST_UPK,
		name: 'spsxi Spot B',
		wkt: 'POINT(-118.4 34.1)',
		date: '2024-07-15T08:00:00-07:00',
		modified_timestamp: 1721044800,
		json_orientation_data: '$jodB'
	})
	CREATE (d)-[:HAS_SPOT]->(s)
	CREATE (i:Image {
		id: '${PREFIX}_imgB1',
		userpkey: $TEST_UPK,
		filename: '${PREFIX}_imgB1.tif',
		image_type: 'thin_section',
		title: 'B thin section',
		annotated: ''
	})
	CREATE (s)-[:HAS_IMAGE]->(i)");
$neodb->query("CREATE (t:Tag {
	id: '${PREFIX}_tagB_ign',
	userpkey: $TEST_UPK,
	type: 'geologic_unit',
	name: 'spsxi B Granite',
	rock_type: 'igneous',
	igneous_rock_class: 'plutonic',
	plutonic_rock_types: 'granite'
})");
$neodb->query("MATCH (s:Spot {id: '${PREFIX}_spotB'}), (t:Tag {id: '${PREFIX}_tagB_ign'})
	CREATE (s)-[:IS_TAGGED]->(t)");

// ---------------------------------------------------------------------------
// SCENARIO C — tail image_type maps to 'other'; vocab table records it.
$neodb->query("MATCH (d:Dataset {id: '${DSET_PUB}'})
	CREATE (s:Spot {
		id: '${PREFIX}_spotC',
		userpkey: $TEST_UPK,
		name: 'spsxi Spot C',
		wkt: 'POINT(-118.3 34.0)',
		modified_timestamp: 1717430400
	})
	CREATE (d)-[:HAS_SPOT]->(s)
	CREATE (i:Image {
		id: '${PREFIX}_imgC1',
		userpkey: $TEST_UPK,
		filename: '${PREFIX}_imgC1.jpg',
		image_type: 'strat_section',
		annotated: '0'
	})
	CREATE (s)-[:HAS_IMAGE]->(i)");

// ---------------------------------------------------------------------------
// SCENARIO D — annotated tristate: image D1 annotated absent (NULL),
// image D2 has annotated='1' (TRUE), image D3 annotated='' (FALSE).
// All three share spot D.
$neodb->query("MATCH (d:Dataset {id: '${DSET_PUB}'})
	CREATE (s:Spot {
		id: '${PREFIX}_spotD',
		userpkey: $TEST_UPK,
		name: 'spsxi Spot D',
		wkt: 'POINT(-118.2 33.9)',
		modified_timestamp: 1717516800
	})
	CREATE (d)-[:HAS_SPOT]->(s)
	CREATE (i1:Image {
		id: '${PREFIX}_imgD1',
		userpkey: $TEST_UPK,
		filename: '${PREFIX}_imgD1.jpg',
		image_type: 'sketch'
	})
	CREATE (i2:Image {
		id: '${PREFIX}_imgD2',
		userpkey: $TEST_UPK,
		filename: '${PREFIX}_imgD2.jpg',
		image_type: 'sketch',
		annotated: '1'
	})
	CREATE (i3:Image {
		id: '${PREFIX}_imgD3',
		userpkey: $TEST_UPK,
		filename: '${PREFIX}_imgD3.jpg',
		image_type: 'sketch',
		annotated: ''
	})
	CREATE (s)-[:HAS_IMAGE]->(i1)
	CREATE (s)-[:HAS_IMAGE]->(i2)
	CREATE (s)-[:HAS_IMAGE]->(i3)");

// ---------------------------------------------------------------------------
// SCENARIO E — image with no filename: SKIPPED per §5.5 servability gate.
$neodb->query("MATCH (d:Dataset {id: '${DSET_PUB}'})
	CREATE (s:Spot {
		id: '${PREFIX}_spotE',
		userpkey: $TEST_UPK,
		name: 'spsxi Spot E',
		wkt: 'POINT(-118.1 33.8)',
		modified_timestamp: 1717603200
	})
	CREATE (d)-[:HAS_SPOT]->(s)
	CREATE (i:Image {
		id: '${PREFIX}_imgE_NOFILE',
		userpkey: $TEST_UPK,
		image_type: 'photo'
	})
	CREATE (s)-[:HAS_IMAGE]->(i)");

// ---------------------------------------------------------------------------
// SCENARIO F — private project image → project_ispublic = FALSE
$neodb->query("MATCH (d:Dataset {id: '${DSET_PRIV}'})
	CREATE (s:Spot {
		id: '${PREFIX}_spotF',
		userpkey: $TEST_UPK,
		name: 'spsxi Spot F',
		wkt: 'POINT(-118.0 33.7)',
		modified_timestamp: 1717689600
	})
	CREATE (d)-[:HAS_SPOT]->(s)
	CREATE (i:Image {
		id: '${PREFIX}_imgF1',
		userpkey: $TEST_UPK,
		filename: '${PREFIX}_imgF1.jpg',
		image_type: 'photo'
	})
	CREATE (s)-[:HAS_IMAGE]->(i)");

// ---------------------------------------------------------------------------
// SCENARIO G — polygon spot → image inherits centroid POINT.
$neodb->query("MATCH (d:Dataset {id: '${DSET_PUB}'})
	CREATE (s:Spot {
		id: '${PREFIX}_spotG',
		userpkey: $TEST_UPK,
		name: 'spsxi Spot G',
		wkt: 'POLYGON((-117.6 33.3, -117.5 33.3, -117.5 33.4, -117.6 33.4, -117.6 33.3))',
		modified_timestamp: 1717776000
	})
	CREATE (d)-[:HAS_SPOT]->(s)
	CREATE (i:Image {
		id: '${PREFIX}_imgG1',
		userpkey: $TEST_UPK,
		filename: '${PREFIX}_imgG1.jpg',
		image_type: 'sample'
	})
	CREATE (s)-[:HAS_IMAGE]->(i)");

// ---------------------------------------------------------------------------
// SCENARIO H — trace_types inheritance: spot has json_trace → image row's
// trace_types array carries the parent's buildTracePath-derived facets.
// Real-prod shape: top-level `trace_type` + sub-classification key.
$jtrH = json_encode(array(
	array('trace_feature' => true, 'trace_type' => 'geologic_struc',
	      'geologic_structure_type' => 'fault'),
	array('trace_feature' => true, 'trace_type' => 'contact',
	      'contact_type' => 'depositional'),
));
$neodb->query("MATCH (d:Dataset {id: '${DSET_PUB}'})
	CREATE (s:Spot {
		id: '${PREFIX}_spotH',
		userpkey: $TEST_UPK,
		name: 'spsxi Spot H',
		wkt: 'LINESTRING(-117.9 33.6, -117.9 33.7)',
		modified_timestamp: 1717862400,
		json_trace: '$jtrH'
	})
	CREATE (d)-[:HAS_SPOT]->(s)
	CREATE (i:Image {
		id: '${PREFIX}_imgH1',
		userpkey: $TEST_UPK,
		filename: '${PREFIX}_imgH1.jpg',
		image_type: 'sketch'
	})
	CREATE (s)-[:HAS_IMAGE]->(i)");

// ---------------------------------------------------------------------------
// SCENARIO L — same-id decoy guard. Decoy user owns an :Image with the
// SAME id as $PREFIX_imgA1. Under TEST_UPK extraction the decoy image
// must NOT leak into TEST_UPK rows (User-anchored walk guarantee).
$neodb->query("CREATE (u:User {
	userpkey: $DECOY_UPK,
	email: 'spsxi-decoy-$DECOY_UPK@test.strabospot.org',
	firstname: 'spsxi', lastname: 'decoy'
})");
$neodb->query("CREATE (p:Project {
	id: '${PROJ_PUB}', userpkey: $DECOY_UPK,
	desc_project_name: 'spsxi DECOY image project'
})");
$neodb->query("MATCH (u:User {userpkey: $DECOY_UPK}),
                     (p:Project {id: '${PROJ_PUB}', userpkey: $DECOY_UPK})
               CREATE (u)-[:HAS_PROJECT]->(p)");
$neodb->query("MATCH (p:Project {id: '${PROJ_PUB}', userpkey: $DECOY_UPK})
	CREATE (d:Dataset {id: '${DSET_PUB}', userpkey: $DECOY_UPK, name: 'spsxi DECOY ds'})
	CREATE (p)-[:HAS_DATASET]->(d)");
$neodb->query("MATCH (d:Dataset {id: '${DSET_PUB}', userpkey: $DECOY_UPK})
	CREATE (s:Spot {
		id: '${PREFIX}_spotA', userpkey: $DECOY_UPK,
		name: 'spsxi DECOY spotA',
		wkt: 'POINT(-100 30)',
		modified_timestamp: 1718000000
	})
	CREATE (d)-[:HAS_SPOT]->(s)
	CREATE (i:Image {
		id: '${PREFIX}_imgA1', userpkey: $DECOY_UPK,
		filename: 'DECOY_imgA1.jpg',
		image_type: 'photo',
		title: 'DECOYIMGUNIQUEKEY_should_not_leak'
	})
	CREATE (s)-[:HAS_IMAGE]->(i)");

echo '  seeded 8 primary spots covering A-H + 1 decoy under same-id Project/Dataset/Spot/Image' . PHP_EOL;
echo '  expected primary image rows after extract: 8 (A1, B1, C1, D1, D2, D3, F1, G1, H1) minus 1 (E NOFILE skipped)' . PHP_EOL;
echo '  actually: 9 images total (A1, B1, C1, D1, D2, D3, F1, G1, H1) — E_NOFILE skipped' . PHP_EOL;

// ===========================================================================
section('2. Run extractor');

$syncBefore = $db->get_var("SELECT last_full_backfill FROM strabosearch.sync_state WHERE source='field'");

$cmd = "php /srv/app/www/searchdb/extractors/field_images.php --source-userpkey=$TEST_UPK --apply 2>&1";
$out = shell_exec($cmd);
$ok = (strpos($out, 'SWAP FAILED') === false && strpos($out, 'FAIL row') === false);
check('extractor exits clean', $ok, $ok ? '' : substr($out, -400));

// ===========================================================================
section('3. Row presence + project context');

$rowCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK AND image_subsystem = 'field'"
);
check('9 image rows landed (E with no filename was skipped)',
	$rowCount === 9, "got $rowCount");

$pubCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK AND project_ispublic = TRUE"
);
$privCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK AND project_ispublic = FALSE"
);
check('public project image rows: 8',  $pubCount  === 8, "got $pubCount");
check('private project image rows: 1', $privCount === 1, "got $privCount");

$subsysDistinct = $db->get_var(
	"SELECT distinct image_subsystem FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK"
);
check("image_subsystem = 'field'", $subsysDistinct === 'field', "got '$subsysDistinct'");

// ===========================================================================
section('4. Scenario A — full-metadata photo');

$rowA = $db->get_row(
	"SELECT image_type, annotated, title, caption, filename,
	        parent_spot_id, ST_AsText(location) AS loc, date_value::text AS dv
	 FROM strabosearch.image_hit WHERE image_id = '${PREFIX}_imgA1'
	   AND project_userpkey = $TEST_UPK"
);
check('A: row found', $rowA !== null);
if ($rowA) {
	check("A: image_type = 'photo'",   $rowA->image_type === 'photo', "got '{$rowA->image_type}'");
	check('A: annotated = TRUE',        $rowA->annotated === 't', "got '{$rowA->annotated}'");
	check('A: title preserved',         $rowA->title === 'IMGAUNIQUEKEY_titletoken', "got '{$rowA->title}'");
	check('A: caption preserved',
		strpos((string)$rowA->caption, 'IMGAUNIQUEKEY_captiontoken') !== false,
		"got '{$rowA->caption}'");
	check('A: filename preserved',      $rowA->filename === "${PREFIX}_imgA1.jpg", "got '{$rowA->filename}'");
	check('A: parent_spot_id linked',   $rowA->parent_spot_id === "${PREFIX}_spotA", "got '{$rowA->parent_spot_id}'");
	check('A: location is POINT',       strpos((string)$rowA->loc, 'POINT') !== false, "got '{$rowA->loc}'");
	check('A: date_value parsed from spot.date',
		$rowA->dv === '2024-06-01', "got '{$rowA->dv}'");
}

// ===========================================================================
section('5. Scenario B — thin_section + parent inheritance (Q4a + Q4b)');

$rowB = $db->get_row(
	"SELECT image_type, orientation_strike, orientation_dip, orientation_features,
	        rock_types, parent_spot_id
	 FROM strabosearch.image_hit WHERE image_id = '${PREFIX}_imgB1'"
);
check('B: row found', $rowB !== null);
if ($rowB) {
	check("B: image_type = 'thin_section'", $rowB->image_type === 'thin_section', "got '{$rowB->image_type}'");
	check('B: orientation_strike inherits 88.5 from parent spot',
		strpos((string)$rowB->orientation_strike, '88.5') !== false,
		"got '{$rowB->orientation_strike}'");
	check('B: orientation_dip inherits 72',
		strpos((string)$rowB->orientation_dip, '72') !== false,
		"got '{$rowB->orientation_dip}'");
	check('B: orientation_features inherits bedding',
		strpos((string)$rowB->orientation_features, 'bedding') !== false,
		"got '{$rowB->orientation_features}'");
	check('B: rock_types inherits igneous:plutonic:granite',
		strpos((string)$rowB->rock_types, 'igneous:plutonic:granite') !== false,
		"got '{$rowB->rock_types}'");
	check('B: parent_spot_id linked', $rowB->parent_spot_id === "${PREFIX}_spotB");
}

// ===========================================================================
section('6. Scenario C — tail image_type maps to "other"');

$rowC = $db->get_row(
	"SELECT image_type, annotated FROM strabosearch.image_hit
	 WHERE image_id = '${PREFIX}_imgC1'"
);
check('C: row found', $rowC !== null);
if ($rowC) {
	check("C: image_type = 'other' (tail mapping)", $rowC->image_type === 'other', "got '{$rowC->image_type}'");
	check("C: annotated='0' coerces to FALSE", $rowC->annotated === 'f', "got '{$rowC->annotated}'");
}

// ===========================================================================
section('7. Scenario D — annotated tristate');

$rowD1 = $db->get_row("SELECT annotated FROM strabosearch.image_hit WHERE image_id = '${PREFIX}_imgD1'");
$rowD2 = $db->get_row("SELECT annotated FROM strabosearch.image_hit WHERE image_id = '${PREFIX}_imgD2'");
$rowD3 = $db->get_row("SELECT annotated FROM strabosearch.image_hit WHERE image_id = '${PREFIX}_imgD3'");
check('D1: annotated absent → NULL', $rowD1 && $rowD1->annotated === null, "got " . var_export($rowD1->annotated ?? '(no row)', true));
check('D2: annotated="1" → TRUE',    $rowD2 && $rowD2->annotated === 't', "got " . var_export($rowD2->annotated ?? '(no row)', true));
check('D3: annotated="" → FALSE',    $rowD3 && $rowD3->annotated === 'f', "got " . var_export($rowD3->annotated ?? '(no row)', true));

// ===========================================================================
section('8. Scenario E — no-filename image SKIPPED');

$rowE = $db->get_row("SELECT image_id FROM strabosearch.image_hit WHERE image_id = '${PREFIX}_imgE_NOFILE'");
check('E: no row for filename-less image', $rowE === null, $rowE ? 'row exists: should be skipped' : '');

// ===========================================================================
section('9. Scenario F — private project ispublic=FALSE');

$rowF = $db->get_row(
	"SELECT project_ispublic, project_id FROM strabosearch.image_hit
	 WHERE image_id = '${PREFIX}_imgF1'"
);
check('F: row found', $rowF !== null);
if ($rowF) {
	check('F: project_ispublic = FALSE', $rowF->project_ispublic === 'f');
	check('F: project_id = priv',         $rowF->project_id === $PROJ_PRIV);
}

// ===========================================================================
section('10. Scenario G — polygon parent → centroid POINT');

$rowG = $db->get_row(
	"SELECT ST_AsText(location) AS loc FROM strabosearch.image_hit
	 WHERE image_id = '${PREFIX}_imgG1'"
);
check('G: row found', $rowG !== null);
if ($rowG) {
	check('G: location is a POINT (polygon centroid)',
		strpos((string)$rowG->loc, 'POINT') !== false, "got '{$rowG->loc}'");
}

// ===========================================================================
section('11. Scenario H — trace_types inheritance');

$rowH = $db->get_row(
	"SELECT trace_types FROM strabosearch.image_hit
	 WHERE image_id = '${PREFIX}_imgH1'"
);
check('H: row found', $rowH !== null);
if ($rowH) {
	check('H: trace_types contains geologic_struc:fault',
		strpos((string)$rowH->trace_types, 'geologic_struc:fault') !== false,
		"got '{$rowH->trace_types}'");
	check('H: trace_types contains contact:depositional',
		strpos((string)$rowH->trace_types, 'contact:depositional') !== false,
		"got '{$rowH->trace_types}'");
}

// ===========================================================================
section('12. imagetext_tsv U1 keyword search');

$titleHit = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK
	   AND imagetext_tsv @@ to_tsquery('imgauniquekey_titletoken')"
);
check('imagetext_tsv finds unique title token (1 hit)', $titleHit === 1, "got $titleHit");

$captionHit = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK
	   AND imagetext_tsv @@ to_tsquery('imgauniquekey_captiontoken')"
);
check('imagetext_tsv finds unique caption token (1 hit)', $captionHit === 1, "got $captionHit");

// Title-only image B should be findable via title alone.
$thinHit = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK
	   AND imagetext_tsv @@ to_tsquery('english', 'thin & section')"
);
check('imagetext_tsv finds the title-only thin_section image', $thinHit >= 1, "got $thinHit");

// ===========================================================================
section('13. vocab_image_type — Field raw → unified upserts');

$photoMap = $db->get_var(
	"SELECT unified_value FROM strabosearch.vocab_image_type
	 WHERE subsystem = 'field' AND normalized_from = 'photo'"
);
check("vocab: 'photo' → 'photo'", $photoMap === 'photo', "got '$photoMap'");

$sketchMap = $db->get_var(
	"SELECT unified_value FROM strabosearch.vocab_image_type
	 WHERE subsystem = 'field' AND normalized_from = 'sketch'"
);
check("vocab: 'sketch' → 'sketch'", $sketchMap === 'sketch', "got '$sketchMap'");

$thinMap = $db->get_var(
	"SELECT unified_value FROM strabosearch.vocab_image_type
	 WHERE subsystem = 'field' AND normalized_from = 'thin_section'"
);
check("vocab: 'thin_section' → 'thin_section'", $thinMap === 'thin_section', "got '$thinMap'");

$stratMap = $db->get_var(
	"SELECT unified_value FROM strabosearch.vocab_image_type
	 WHERE subsystem = 'field' AND normalized_from = 'strat_section'"
);
check("vocab: 'strat_section' → 'other'", $stratMap === 'other', "got '$stratMap'");

$sampleMap = $db->get_var(
	"SELECT unified_value FROM strabosearch.vocab_image_type
	 WHERE subsystem = 'field' AND normalized_from = 'sample'"
);
check("vocab: 'sample' → 'sample'", $sampleMap === 'sample', "got '$sampleMap'");

// ===========================================================================
section('14. sync_state NOT updated on partial run');

$syncAfter = $db->get_var("SELECT last_full_backfill FROM strabosearch.sync_state WHERE source='field'");
check('sync_state.field last_full_backfill unchanged after --source-userpkey',
	$syncBefore === $syncAfter,
	'before=' . var_export($syncBefore, true) . ' after=' . var_export($syncAfter, true));

// ===========================================================================
section('15. Idempotency — re-run produces same row set');

$out2 = shell_exec($cmd);
$rowCount2 = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK AND image_subsystem = 'field'"
);
check('idempotent re-run: still 9 rows', $rowCount2 === 9, "got $rowCount2");

$rowB2 = $db->get_row(
	"SELECT rock_types FROM strabosearch.image_hit WHERE image_id = '${PREFIX}_imgB1'"
);
check('idempotent: spot_B image still inherits igneous:plutonic:granite',
	$rowB2 && strpos((string)$rowB2->rock_types, 'igneous:plutonic:granite') !== false);

// ===========================================================================
section('16. Scenario L — same-id decoy must NOT cross-contaminate');

// Decoy User owns an :Image with the SAME id ($PREFIX_imgA1) AND the same
// id under a Spot/Dataset/Project that share ids with primary fixtures.
// TEST_UPK's image_hit slice must contain only TEST_UPK's image; the
// decoy's image must not appear under TEST_UPK rows.
$decoyLeak = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK
	   AND title = 'DECOYIMGUNIQUEKEY_should_not_leak'"
);
check('decoy image NOT under TEST_UPK rows (title check)',
	$decoyLeak === 0, "got $decoyLeak");

$decoyKeywordLeak = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK
	   AND imagetext_tsv @@ to_tsquery('decoyimguniquekey_should_not_leak')"
);
check('decoy keyword NOT in TEST_UPK imagetext_tsv',
	$decoyKeywordLeak === 0, "got $decoyKeywordLeak");

$testUserRowCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK"
);
check('TEST_UPK image row count unaffected by decoy (still 9)',
	$testUserRowCount === 9, "got $testUserRowCount");

// ===========================================================================
section('17. Cleanup');

$neodb->query("MATCH (n) WHERE n.id =~ '$PREFIX.*' OR n.id =~ '$DECOY_PFX.*' DETACH DELETE n");
$neodb->query("MATCH (u:User) WHERE u.userpkey IN [$TEST_UPK, $DECOY_UPK] DETACH DELETE u");
$db->query("DELETE FROM strabosearch.image_hit WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK)");
$db->query("DROP TABLE IF EXISTS strabosearch.image_hit_staging_field");
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
