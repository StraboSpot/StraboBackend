<?php
/**
 * File: smoke_test_micro_extractor.php
 * Description: Permanent smoke suite for the Micro extractor — verifies
 *              that searchdb/extractors/micro.php correctly populates
 *              BOTH strabosearch.item_hit (item_type='micrograph') AND
 *              strabosearch.image_hit (image_subsystem='micro') rows
 *              from a single source pass.
 *
 *              Hermetic: seeds strabomicro fixtures under an isolated
 *              userpkey (94505), runs the extractor with
 *              --source-userpkey=94505 --apply, queries both target
 *              tables for the expected per-micrograph column values,
 *              tears down.
 *
 *              Scenarios covered:
 *                A. Public project + SEM micrograph (Backscatter
 *                   Electron). item_hit + image_hit both emit, share
 *                   identity, project context, and native Micro columns.
 *                   image_type unified to 'micrograph_sem'.
 *                B. Private project, optical micrograph (Cross Polarized
 *                   Light) → 'micrograph_optical'. Test ispublic=FALSE
 *                   inherits through both rows.
 *                C. Thin section (raw imagetype contains "thin section")
 *                   → 'thin_section'.
 *                D. Other vocab (Phase Map / CL) → 'micrograph_other'.
 *                E. Minerals + mineral_methods arrays — micrograph has
 *                   two mineralogies, each with its own minerals.
 *                F. Instrument + detector — instrument_type and
 *                   detector_type both populated from the joined
 *                   instrument and instrumentdetector rows.
 *                G. has_microstructure = TRUE via fabricinfo row;
 *                   another micrograph with no structural data → FALSE.
 *                H. Sample lat/lng → location is the sample's point.
 *                I. searchtext_tsv finds unique tokens from micrograph
 *                   name + notes + sample label/sampleid + project name.
 *                J. image_hit-specific: parent_sample_id is the sample's
 *                   strabo_id; parent_spot_id is NULL; filename =
 *                   <strabo_id>.jpg; imagetext_tsv = title + caption.
 *                K. vocab_image_type: all raw → unified pairs upserted
 *                   under subsystem='micro'.
 *                L. Atomic swap idempotency: re-running gives the same
 *                   row set.
 *                M. Same-id decoy: a second user owns a project with
 *                   the SAME strabo_id as the primary; under TEST_UPK
 *                   extraction the decoy must not appear.
 *                N. sync_state NOT updated on --source-userpkey
 *                   partial run.
 *
 *              Self-contained: cleans up before and after. Exits
 *              non-zero on any failure so it can gate CI.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_micro_extractor.php
 *
 * @package    StraboSearch Phase 2 extractors
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$TEST_UPK    = 94505;
$DECOY_UPK   = 94506;
$PREFIX      = 'spsxm94505';   // strabosearch-eXtractor-Micro + isolation upk
$DECOY_PFX   = 'spsxm94506';

$PROJ_PUB    = $PREFIX . '_pub';
$PROJ_PRIV   = $PREFIX . '_priv';

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

// All Micro fixture chains rooted at the two test projects (primary +
// decoy). Drop child rows by FK cascade going up: minerals → mineralogy
// → micrograph; detectors → instruments → micrograph; structural infos →
// micrograph; micrograph → sample → dataset → project.
function micro_drop_chain($db, $upk, $strabo_id_prefix) {
	$mids = $db->get_results("
		SELECT mm.id FROM strabomicro.micro_micrographmetadata mm
		JOIN strabomicro.micro_samplemetadata sm ON sm.id = mm.sample_id
		JOIN strabomicro.micro_datasetmetadata dm ON dm.id = sm.dataset_id
		JOIN strabomicro.micro_projectmetadata pm ON pm.id = dm.project_id
		WHERE pm.userpkey = $upk AND pm.strabo_id LIKE '${strabo_id_prefix}_%'
	");
	if ($mids) foreach ((array)$mids as $r) {
		$mid = (int)$r->id;
		$db->query("DELETE FROM strabomicro.micro_mineral WHERE mineralogy_id IN (SELECT id FROM strabomicro.micro_mineralogy WHERE micrograph_id = $mid)");
		$db->query("DELETE FROM strabomicro.micro_mineralogy WHERE micrograph_id = $mid");
		$db->query("DELETE FROM strabomicro.micro_instrumentdetector WHERE instrument_id IN (SELECT id FROM strabomicro.micro_instrument WHERE micrograph_id = $mid)");
		$db->query("DELETE FROM strabomicro.micro_instrument WHERE micrograph_id = $mid");
		$db->query("DELETE FROM strabomicro.micro_fabricinfo WHERE micrograph_id = $mid");
		// Tags amendment fixtures: junction rows + spots on the micrograph.
		$db->query("DELETE FROM strabomicro.micro_micrograph_tag WHERE micrograph_id = $mid");
		$db->query("DELETE FROM strabomicro.micro_spot_tag WHERE spot_id IN (SELECT id FROM strabomicro.micro_spotmetadata WHERE micrograph_id = $mid)");
		$db->query("DELETE FROM strabomicro.micro_spotmetadata WHERE micrograph_id = $mid");
	}
	$db->query("DELETE FROM strabomicro.micro_tag WHERE strabo_id LIKE '${strabo_id_prefix}_tag%'");
	$db->query("DELETE FROM strabomicro.micro_micrographmetadata WHERE sample_id IN (
		SELECT sm.id FROM strabomicro.micro_samplemetadata sm
		JOIN strabomicro.micro_datasetmetadata dm ON dm.id = sm.dataset_id
		JOIN strabomicro.micro_projectmetadata pm ON pm.id = dm.project_id
		WHERE pm.userpkey = $upk AND pm.strabo_id LIKE '${strabo_id_prefix}_%')");
	$db->query("DELETE FROM strabomicro.micro_samplemetadata WHERE dataset_id IN (
		SELECT dm.id FROM strabomicro.micro_datasetmetadata dm
		JOIN strabomicro.micro_projectmetadata pm ON pm.id = dm.project_id
		WHERE pm.userpkey = $upk AND pm.strabo_id LIKE '${strabo_id_prefix}_%')");
	$db->query("DELETE FROM strabomicro.micro_datasetmetadata WHERE project_id IN (
		SELECT id FROM strabomicro.micro_projectmetadata WHERE userpkey = $upk AND strabo_id LIKE '${strabo_id_prefix}_%')");
	$db->query("DELETE FROM strabomicro.micro_projectmetadata WHERE userpkey = $upk AND strabo_id LIKE '${strabo_id_prefix}_%'");
}
micro_drop_chain($db, $TEST_UPK, $PREFIX);
micro_drop_chain($db, $DECOY_UPK, $PREFIX);  // decoy uses same prefix on purpose

$db->query("DELETE FROM strabosearch.item_hit  WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK) AND project_subsystem = 'micro'");
$db->query("DELETE FROM strabosearch.image_hit WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK) AND project_subsystem = 'micro'");
$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_micro");
$db->query("DROP TABLE IF EXISTS strabosearch.image_hit_staging_micro");
$db->query("DELETE FROM users WHERE pkey IN ($TEST_UPK, $DECOY_UPK)");
$db->query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active)
	VALUES ($TEST_UPK, 'spsxm', 'fixture',
	        'spsxm-fixture-$TEST_UPK@test.strabospot.org', 'x', 'x', false),
	       ($DECOY_UPK, 'spsxm', 'decoy',
	        'spsxm-decoy-$DECOY_UPK@test.strabospot.org', 'x', 'x', false)");

echo '  cleared fixture micro chain + item_hit/image_hit slices + users for upk '
	. $TEST_UPK . ' and decoy ' . $DECOY_UPK . PHP_EOL;

// ===========================================================================
section('1. Seed strabomicro fixtures');

// Helper: insert a full Micro chain (project → dataset → sample → micrograph)
// and return the micrograph row's id.
function seed_micro($db, $proj_strabo, $proj_name, $upk, $ispublic, $proj_mt,
                    $samp_strabo, $samp_label, $samp_sampleid, $lng, $lat,
                    $micro_strabo, $micro_name, $micro_notes, $micro_imagetype) {
	$proj_name_esc      = pg_escape_string($proj_name);
	$proj_strabo_esc    = pg_escape_string($proj_strabo);
	$pubLit             = $ispublic ? 'true' : 'false';
	$proj_mt_esc        = pg_escape_string($proj_mt);
	// Wrapper's query() captures insert_id via SELECT lastval() for INSERTs;
	// get_var would re-execute the INSERT as a SELECT and fail.
	$db->query("INSERT INTO strabomicro.micro_projectmetadata
		(strabo_id, userpkey, name, ispublic, modifiedtimestamp, notes)
		VALUES ('$proj_strabo_esc', $upk, '$proj_name_esc', $pubLit, '$proj_mt_esc', 'proj notes')");
	$pid = $db->insert_id;

	$db->query("INSERT INTO strabomicro.micro_datasetmetadata
		(project_id, strabo_id, name) VALUES ($pid, '" . pg_escape_string($proj_strabo . '_ds') . "', 'ds')");
	$did = $db->insert_id;

	$samp_label_esc    = pg_escape_string($samp_label);
	$samp_sampleid_esc = pg_escape_string($samp_sampleid);
	$samp_strabo_esc   = pg_escape_string($samp_strabo);
	$lngLit = ($lng === null) ? 'NULL' : (string)(float)$lng;
	$latLit = ($lat === null) ? 'NULL' : (string)(float)$lat;
	$db->query("INSERT INTO strabomicro.micro_samplemetadata
		(dataset_id, strabo_id, label, sampleid, longitude, latitude, samplenotes)
		VALUES ($did, '$samp_strabo_esc', '$samp_label_esc', '$samp_sampleid_esc',
		        $lngLit, $latLit, 'samp notes')");
	$sid = $db->insert_id;

	$mname_esc  = pg_escape_string($micro_name);
	$mnotes_esc = pg_escape_string($micro_notes);
	$mtype_esc  = pg_escape_string((string)$micro_imagetype);
	$micro_strabo_esc = pg_escape_string($micro_strabo);
	$db->query("INSERT INTO strabomicro.micro_micrographmetadata
		(sample_id, strabo_id, name, notes, imagetype, width, height)
		VALUES ($sid, '$micro_strabo_esc', '$mname_esc', '$mnotes_esc',
		        '$mtype_esc', 1024, 768)");
	return (int)$db->insert_id;
}

// ---------------------------------------------------------------------------
// SCENARIO A — Public project, SEM micrograph, with minerals + instrument + detector + fabricinfo.
$midA = seed_micro($db,
	$PROJ_PUB, 'spsxm SEM Project', $TEST_UPK, true, '1717257600000',
	$PREFIX . '_sampleA', 'SAMPA UNIQKEY_samplelabel', 'SAMP-A-001',
	-118.5, 34.2,
	$PREFIX . '_imgA', 'spsxm SEM MicrographA UNIQKEY_microname',
	'UNIQKEY_microcaption great backscatter image',
	'Backscatter Electron (BSE)');

// Minerals: two mineralogies, each with its own minerals + methods.
$db->query("INSERT INTO strabomicro.micro_mineralogy
	(micrograph_id, mineralogymethod) VALUES ($midA, 'Point counting')");
$mgA1 = $db->insert_id;
$db->query("INSERT INTO strabomicro.micro_mineral (mineralogy_id, name, percentage)
	VALUES ($mgA1, 'Quartz', 60), ($mgA1, 'Feldspar', 30)");
$db->query("INSERT INTO strabomicro.micro_mineralogy
	(micrograph_id, mineralogymethod) VALUES ($midA, 'EDS analysis')");
$mgA2 = $db->insert_id;
$db->query("INSERT INTO strabomicro.micro_mineral (mineralogy_id, name, percentage)
	VALUES ($mgA2, 'Olivine', 10)");

// Instrument + detector
$db->query("INSERT INTO strabomicro.micro_instrument
	(micrograph_id, instrumenttype, instrumentbrand) VALUES ($midA,
	'Scanning Electron Microscopy (SEM)', 'JEOL')");
$instA = $db->insert_id;
$db->query("INSERT INTO strabomicro.micro_instrumentdetector
	(instrument_id, detectortype) VALUES ($instA, 'Backscattered Electron')");

// fabricinfo row to trigger has_microstructure = TRUE
$db->query("INSERT INTO strabomicro.micro_fabricinfo (micrograph_id) VALUES ($midA)");

// Tags amendment (U10) — exercise ALL FOUR Micro tag-name sources on midA:
// 1. micrograph junction tag (micro_micrograph_tag → micro_tag)
$db->query("INSERT INTO strabomicro.micro_tag (strabo_id, name, tagtype)
	VALUES ('{$PREFIX}_tag1', 'spsxm JunctionTag UNIQTAG_junc', 'Other')");
$tagJuncA = $db->insert_id;
$db->query("INSERT INTO strabomicro.micro_micrograph_tag (micrograph_id, tag_id)
	VALUES ($midA, $tagJuncA)");
// 2. micrograph tags_json (client-written array of {name, tagType})
$db->query("UPDATE strabomicro.micro_micrographmetadata
	SET tags_json = '[{\"id\":\"1\",\"name\":\"spsxm JsonTag UNIQTAG_mjson\",\"tagType\":\"Other\"}]'
	WHERE id = $midA");
// 3. spot on the micrograph carrying its own tags_json
$db->query("INSERT INTO strabomicro.micro_spotmetadata (micrograph_id, strabo_id, name, tags_json)
	VALUES ($midA, '{$PREFIX}_spot1', 'spsxm spot one',
	        '[{\"name\":\"spsxm SpotJsonTag UNIQTAG_sjson\",\"tagType\":\"Other\"}]')");
$spotA = $db->insert_id;
// 4. spot junction tag (micro_spot_tag → micro_tag)
$db->query("INSERT INTO strabomicro.micro_tag (strabo_id, name, tagtype)
	VALUES ('{$PREFIX}_tag2', 'spsxm SpotJunctionTag UNIQTAG_sjunc', 'Other')");
$tagSjuncA = $db->insert_id;
$db->query("INSERT INTO strabomicro.micro_spot_tag (spot_id, tag_id)
	VALUES ($spotA, $tagSjuncA)");

// ---------------------------------------------------------------------------
// SCENARIO B — Private project, optical micrograph
$midB = seed_micro($db,
	$PROJ_PRIV, 'spsxm Optical Project', $TEST_UPK, false, '1717344000000',
	$PREFIX . '_sampleB', 'SAMPB', 'SAMP-B-002', null, null,
	$PREFIX . '_imgB', 'spsxm Optical Micrograph',
	'thin section description', 'Cross Polarized Light');

// ---------------------------------------------------------------------------
// SCENARIO C — Thin section vocab (raw imagetype contains "thin section")
$midC = seed_micro($db,
	$PROJ_PUB . '_proj2', 'spsxm Thin Project', $TEST_UPK, true, '1717430400000',
	$PREFIX . '_sampleC', 'SAMPC', 'SAMP-C-003', null, null,
	$PREFIX . '_imgC', 'spsxm Thin Section Image', '',
	'Thin Section Overview');

// ---------------------------------------------------------------------------
// SCENARIO D — "Other" vocab (Phase Map)
$midD = seed_micro($db,
	$PROJ_PUB . '_proj3', 'spsxm Phase Project', $TEST_UPK, true, '1717516800000',
	$PREFIX . '_sampleD', 'SAMPD', 'SAMP-D-004', null, null,
	$PREFIX . '_imgD', 'spsxm Phase Map', '',
	'Phase Map');

// ---------------------------------------------------------------------------
// SCENARIO G2 — "bare" micrograph with no structural data → has_microstructure=FALSE
$midG2 = seed_micro($db,
	$PROJ_PUB . '_proj4', 'spsxm Bare Project', $TEST_UPK, true, '1717603200000',
	$PREFIX . '_sampleG2', 'SAMPG2', 'SAMP-G2-005', null, null,
	$PREFIX . '_imgG2', 'spsxm Bare Micrograph', '',
	'Reflected Light');

// ---------------------------------------------------------------------------
// SCENARIO M — DECOY: a second user owns a project with the SAME strabo_id
// as $PROJ_PUB. Under TEST_UPK extraction the decoy's micrograph must NOT
// appear. (Note: strabomicro.project's identity is the SQL serial; the
// strabo_id is the cross-system id and is not unique across (user, system)
// per the [[project_strabosamples_cross_system_auth]] memory. This guards
// against accidental id-only matching.)
$midDecoy = seed_micro($db,
	$PROJ_PUB, 'spsxm DECOY Same-id Project', $DECOY_UPK, true, '1717689600000',
	$PREFIX . '_sampleDECOY', 'DECOYSAMP UNIQKEY_decoyleak', 'SAMP-DECOY',
	-100.0, 30.0,
	$PREFIX . '_imgA', 'spsxm DECOY Micrograph UNIQKEY_decoymicroname', '',
	'Cross Polarized Light');

echo "  seeded 5 primary micrographs (A/B/C/D/G2) + 1 decoy (same strabo_id as A)\n";

// ===========================================================================
section('2. Run extractor');

$syncBefore = $db->get_var("SELECT last_full_backfill FROM strabosearch.sync_state WHERE source='micro'");

$cmd = "php /srv/app/www/searchdb/extractors/micro.php --source-userpkey=$TEST_UPK --apply 2>&1";
$out = shell_exec($cmd);
$ok = (strpos($out, 'SWAP FAILED') === false && strpos($out, 'FAIL row') === false);
check('extractor exits clean', $ok, $ok ? '' : substr($out, -400));

// ===========================================================================
section('3. Row presence + project context');

$itemCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'"
);
check('5 item_hit rows for TEST_UPK micrographs',
	$itemCount === 5, "got $itemCount");

$imageCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK AND image_subsystem = 'micro'"
);
check('5 image_hit rows for TEST_UPK micrographs',
	$imageCount === 5, "got $imageCount");

$pubCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'
	   AND project_ispublic = TRUE"
);
$privCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'
	   AND project_ispublic = FALSE"
);
check('public item_hit rows: 4',  $pubCount  === 4, "got $pubCount");
check('private item_hit rows: 1', $privCount === 1, "got $privCount");

$itype = $db->get_var(
	"SELECT distinct item_type FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'"
);
check("item_type = 'micrograph'", $itype === 'micrograph', "got '$itype'");

// ===========================================================================
section('4. Scenario A — SEM + minerals + instrument + detector + fabric');

$rowAItem = $db->get_row(
	"SELECT minerals, mineral_methods, instrument_type, detector_type,
	        has_microstructure, has_samples, has_images, has_orientation, has_strat,
	        ST_AsText(location) AS loc
	 FROM strabosearch.item_hit
	 WHERE item_id = '${PREFIX}_imgA' AND project_userpkey = $TEST_UPK"
);
check('A item_hit: row found', $rowAItem !== null);
if ($rowAItem) {
	check('A: minerals contains Quartz',
		strpos((string)$rowAItem->minerals, 'Quartz') !== false,
		"got '{$rowAItem->minerals}'");
	check('A: minerals contains Feldspar',
		strpos((string)$rowAItem->minerals, 'Feldspar') !== false,
		"got '{$rowAItem->minerals}'");
	check('A: minerals contains Olivine',
		strpos((string)$rowAItem->minerals, 'Olivine') !== false,
		"got '{$rowAItem->minerals}'");
	check('A: mineral_methods contains Point counting',
		strpos((string)$rowAItem->mineral_methods, 'Point counting') !== false,
		"got '{$rowAItem->mineral_methods}'");
	check('A: mineral_methods contains EDS analysis',
		strpos((string)$rowAItem->mineral_methods, 'EDS analysis') !== false,
		"got '{$rowAItem->mineral_methods}'");
	check('A: instrument_type populated',
		strpos((string)$rowAItem->instrument_type, 'Scanning Electron') !== false,
		"got '{$rowAItem->instrument_type}'");
	check('A: detector_type populated',
		(string)$rowAItem->detector_type === 'Backscattered Electron',
		"got '{$rowAItem->detector_type}'");
	check('A: has_microstructure = TRUE (fabricinfo row)',
		$rowAItem->has_microstructure === 't');
	check('A: has_samples = TRUE',  $rowAItem->has_samples  === 't');
	check('A: has_images = TRUE',   $rowAItem->has_images   === 't');
	check('A: has_orientation = FALSE', $rowAItem->has_orientation === 'f');
	check('A: has_strat = FALSE',   $rowAItem->has_strat    === 'f');
	check('A: location is the sample POINT(-118.5 34.2)',
		strpos((string)$rowAItem->loc, 'POINT(-118.5 34.2)') !== false,
		"got '{$rowAItem->loc}'");
}

// image_hit-side counterpart of A
$rowAImg = $db->get_row(
	"SELECT image_type, title, caption, filename, parent_spot_id, parent_sample_id,
	        annotated, minerals, instrument_type
	 FROM strabosearch.image_hit
	 WHERE image_id = '${PREFIX}_imgA' AND project_userpkey = $TEST_UPK"
);
check('A image_hit: row found', $rowAImg !== null);
if ($rowAImg) {
	check("A image: image_type = 'micrograph_sem'",
		$rowAImg->image_type === 'micrograph_sem', "got '{$rowAImg->image_type}'");
	check('A image: title = micrograph name',
		strpos((string)$rowAImg->title, 'UNIQKEY_microname') !== false,
		"got '{$rowAImg->title}'");
	check('A image: caption = micrograph notes',
		strpos((string)$rowAImg->caption, 'UNIQKEY_microcaption') !== false,
		"got '{$rowAImg->caption}'");
	check('A image: filename = <strabo_id>.jpg',
		$rowAImg->filename === "${PREFIX}_imgA.jpg",
		"got '{$rowAImg->filename}'");
	check('A image: parent_spot_id = NULL',
		$rowAImg->parent_spot_id === null,
		'got ' . var_export($rowAImg->parent_spot_id, true));
	check('A image: parent_sample_id = sample strabo_id',
		$rowAImg->parent_sample_id === "${PREFIX}_sampleA",
		"got '{$rowAImg->parent_sample_id}'");
	check('A image: annotated = NULL (no Micro concept)',
		$rowAImg->annotated === null,
		'got ' . var_export($rowAImg->annotated, true));
	check('A image: minerals also denormalized to image_hit',
		strpos((string)$rowAImg->minerals, 'Quartz') !== false,
		"got '{$rowAImg->minerals}'");
	check('A image: instrument_type also denormalized to image_hit',
		strpos((string)$rowAImg->instrument_type, 'Scanning Electron') !== false,
		"got '{$rowAImg->instrument_type}'");
}

// ===========================================================================
section('4b. Tags amendment — tag_names from all four Micro sources (U10, name-only)');

foreach (array('item_hit' => "item_id = '{$PREFIX}_imgA' AND item_userpkey = $TEST_UPK AND project_subsystem = 'micro'",
               'image_hit' => "image_id = '{$PREFIX}_imgA' AND image_userpkey = $TEST_UPK AND image_subsystem = 'micro'") as $tbl => $where) {
	// image_hit has imagetext_tsv, not searchtext_tsv — the U1 safety-net
	// check only applies to item_hit.
	$bagExpr = ($tbl === 'item_hit')
		? "(searchtext_tsv @@ to_tsquery('uniqtag_mjson'))"
		: 'TRUE';
	$rowT = $db->get_row(
		"SELECT tag_names, tag_types,
		        (tag_text_tsv @@ to_tsquery('uniqtag_junc & uniqtag_mjson & uniqtag_sjson & uniqtag_sjunc')) AS tsv_all,
		        $bagExpr AS bag_hit
		 FROM strabosearch.$tbl WHERE $where"
	);
	check("$tbl: tag row found", $rowT !== null);
	if ($rowT) {
		$tn = (string)$rowT->tag_names;
		check("$tbl: tag_names carries all four source names",
			strpos($tn, 'UNIQTAG_junc') !== false && strpos($tn, 'UNIQTAG_mjson') !== false
			&& strpos($tn, 'UNIQTAG_sjson') !== false && strpos($tn, 'UNIQTAG_sjunc') !== false,
			'got ' . $tn);
		check("$tbl: tag_types NULL (F11 is Field-only; Micro is name-only)",
			$rowT->tag_types === null, 'got ' . var_export($rowT->tag_types, true));
		check("$tbl: tag_text_tsv matches all four tag tokens", $rowT->tsv_all === 't');
		if ($tbl === 'item_hit') {
			check("$tbl: searchtext_tsv catches a tag token (U1 safety net)",
				$rowT->bag_hit === 't');
		}
	}
}

$bareTags = $db->get_row(
	"SELECT tag_names, tag_text_tsv FROM strabosearch.item_hit
	 WHERE item_id = '{$PREFIX}_imgG2' AND item_userpkey = $TEST_UPK"
);
check('untagged micrograph has NULL tag columns',
	$bareTags !== null && $bareTags->tag_names === null && $bareTags->tag_text_tsv === null,
	$bareTags ? 'got ' . var_export($bareTags->tag_names, true) : 'row missing');

$microVocab = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.vocab_tag_type WHERE subsystem = 'micro'"
);
check('vocab_tag_type gets NO micro rows (Field-only vocab)', $microVocab === 0,
	"got $microVocab");

// ===========================================================================
section('5. Scenario B — optical vocab + ispublic=FALSE flow-through');

$rowBImg = $db->get_row(
	"SELECT image_type, project_ispublic FROM strabosearch.image_hit
	 WHERE image_id = '${PREFIX}_imgB' AND project_userpkey = $TEST_UPK"
);
check('B image_hit: row found', $rowBImg !== null);
if ($rowBImg) {
	check("B: image_type = 'micrograph_optical'",
		$rowBImg->image_type === 'micrograph_optical', "got '{$rowBImg->image_type}'");
	check('B: project_ispublic = FALSE',
		$rowBImg->project_ispublic === 'f', "got '{$rowBImg->project_ispublic}'");
}
$rowBItem = $db->get_row(
	"SELECT project_ispublic FROM strabosearch.item_hit
	 WHERE item_id = '${PREFIX}_imgB' AND project_userpkey = $TEST_UPK"
);
check('B item_hit: project_ispublic = FALSE',
	$rowBItem && $rowBItem->project_ispublic === 'f');

// ===========================================================================
section('6. Scenario C — thin_section vocab');

$rowCImg = $db->get_row(
	"SELECT image_type FROM strabosearch.image_hit
	 WHERE image_id = '${PREFIX}_imgC' AND project_userpkey = $TEST_UPK"
);
check('C: row found', $rowCImg !== null);
if ($rowCImg) {
	check("C: image_type = 'thin_section'",
		$rowCImg->image_type === 'thin_section', "got '{$rowCImg->image_type}'");
}

// ===========================================================================
section('7. Scenario D — micrograph_other vocab');

$rowDImg = $db->get_row(
	"SELECT image_type FROM strabosearch.image_hit
	 WHERE image_id = '${PREFIX}_imgD' AND project_userpkey = $TEST_UPK"
);
check('D: row found', $rowDImg !== null);
if ($rowDImg) {
	check("D: image_type = 'micrograph_other'",
		$rowDImg->image_type === 'micrograph_other', "got '{$rowDImg->image_type}'");
}

// ===========================================================================
section('8. Scenario G2 — bare micrograph: has_microstructure = FALSE');

$rowG2 = $db->get_row(
	"SELECT has_microstructure FROM strabosearch.item_hit
	 WHERE item_id = '${PREFIX}_imgG2' AND project_userpkey = $TEST_UPK"
);
check('G2: row found', $rowG2 !== null);
if ($rowG2) {
	check('G2: has_microstructure = FALSE',
		$rowG2->has_microstructure === 'f', "got '{$rowG2->has_microstructure}'");
}

// ===========================================================================
section('9. searchtext_tsv U1 keyword search');

$microNameHits = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'
	   AND searchtext_tsv @@ to_tsquery('uniqkey_microname')"
);
check('searchtext finds unique micrograph-name token (1 hit)',
	$microNameHits === 1, "got $microNameHits");

$captionHits = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'
	   AND searchtext_tsv @@ to_tsquery('uniqkey_microcaption')"
);
check('searchtext finds unique micrograph-notes token (1 hit)',
	$captionHits === 1, "got $captionHits");

$sampleLabelHits = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'
	   AND searchtext_tsv @@ to_tsquery('uniqkey_samplelabel')"
);
check('searchtext finds unique sample-label token (1 hit)',
	$sampleLabelHits === 1, "got $sampleLabelHits");

// imagetext_tsv on image_hit side
$imageTextHits = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK AND image_subsystem = 'micro'
	   AND imagetext_tsv @@ to_tsquery('uniqkey_microcaption')"
);
check('image_hit imagetext_tsv finds unique caption token',
	$imageTextHits === 1, "got $imageTextHits");

// ===========================================================================
section('10. vocab_image_type — Micro raw → unified upserts');

$bseMap = $db->get_var(
	"SELECT unified_value FROM strabosearch.vocab_image_type
	 WHERE subsystem = 'micro' AND normalized_from = 'Backscatter Electron (BSE)'"
);
check("vocab: 'Backscatter Electron (BSE)' → 'micrograph_sem'",
	$bseMap === 'micrograph_sem', "got '$bseMap'");

$cplMap = $db->get_var(
	"SELECT unified_value FROM strabosearch.vocab_image_type
	 WHERE subsystem = 'micro' AND normalized_from = 'Cross Polarized Light'"
);
check("vocab: 'Cross Polarized Light' → 'micrograph_optical'",
	$cplMap === 'micrograph_optical', "got '$cplMap'");

$thinMap = $db->get_var(
	"SELECT unified_value FROM strabosearch.vocab_image_type
	 WHERE subsystem = 'micro' AND normalized_from = 'Thin Section Overview'"
);
check("vocab: 'Thin Section Overview' → 'thin_section'",
	$thinMap === 'thin_section', "got '$thinMap'");

$phaseMap = $db->get_var(
	"SELECT unified_value FROM strabosearch.vocab_image_type
	 WHERE subsystem = 'micro' AND normalized_from = 'Phase Map'"
);
check("vocab: 'Phase Map' → 'micrograph_other'",
	$phaseMap === 'micrograph_other', "got '$phaseMap'");

$reflMap = $db->get_var(
	"SELECT unified_value FROM strabosearch.vocab_image_type
	 WHERE subsystem = 'micro' AND normalized_from = 'Reflected Light'"
);
check("vocab: 'Reflected Light' → 'micrograph_optical'",
	$reflMap === 'micrograph_optical', "got '$reflMap'");

// ===========================================================================
section('11. sync_state NOT updated on partial run');

$syncAfter = $db->get_var("SELECT last_full_backfill FROM strabosearch.sync_state WHERE source='micro'");
check('sync_state.micro last_full_backfill unchanged after --source-userpkey',
	$syncBefore === $syncAfter,
	'before=' . var_export($syncBefore, true) . ' after=' . var_export($syncAfter, true));

// ===========================================================================
section('12. Idempotency — re-run produces same row set');

shell_exec($cmd);
$itemCount2 = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'"
);
$imageCount2 = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK AND image_subsystem = 'micro'"
);
check('idempotent re-run: still 5 item_hit rows', $itemCount2 === 5, "got $itemCount2");
check('idempotent re-run: still 5 image_hit rows', $imageCount2 === 5, "got $imageCount2");

$rowA2 = $db->get_row(
	"SELECT minerals FROM strabosearch.item_hit WHERE item_id = '${PREFIX}_imgA'"
);
check('idempotent: A still has Quartz mineral',
	$rowA2 && strpos((string)$rowA2->minerals, 'Quartz') !== false);

// ===========================================================================
section('13. Scenario M — same-id decoy must NOT cross-contaminate');

// Decoy user owns a project with strabo_id = PROJ_PUB and a micrograph with
// strabo_id = PREFIX_imgA. Under TEST_UPK extraction the decoy must NOT
// appear in TEST_UPK rows.
$decoyLeak = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'
	   AND searchtext_tsv @@ to_tsquery('uniqkey_decoymicroname')"
);
check('decoy micrograph-name token NOT in TEST_UPK searchtext',
	$decoyLeak === 0, "got $decoyLeak");

$decoySampleLeak = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'
	   AND searchtext_tsv @@ to_tsquery('uniqkey_decoyleak')"
);
check('decoy sample-label token NOT in TEST_UPK searchtext',
	$decoySampleLeak === 0, "got $decoySampleLeak");

$testItemCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'micro'"
);
check('TEST_UPK row count unaffected by decoy (still 5)',
	$testItemCount === 5, "got $testItemCount");

// ===========================================================================
section('14. Cleanup');

micro_drop_chain($db, $TEST_UPK, $PREFIX);
micro_drop_chain($db, $DECOY_UPK, $PREFIX);
$db->query("DELETE FROM strabosearch.item_hit  WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK) AND project_subsystem = 'micro'");
$db->query("DELETE FROM strabosearch.image_hit WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK) AND project_subsystem = 'micro'");
$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_micro");
$db->query("DROP TABLE IF EXISTS strabosearch.image_hit_staging_micro");
$db->query("DELETE FROM users WHERE pkey IN ($TEST_UPK, $DECOY_UPK)");
echo '  cleared fixture chain + slices + staging + users' . PHP_EOL;

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
