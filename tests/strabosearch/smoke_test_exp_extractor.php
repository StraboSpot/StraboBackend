<?php
/**
 * File: smoke_test_exp_extractor.php
 * Description: Permanent smoke suite for the Experimental extractor —
 *              verifies that searchdb/extractors/exp.php correctly
 *              populates strabosearch.item_hit rows tagged
 *              item_type='experiment' from straboexp.* sources.
 *
 *              Hermetic: seeds straboexp fixtures under an isolated
 *              userpkey (94507), runs the extractor with
 *              --source-userpkey=94507 --apply, queries item_hit for
 *              the expected per-experiment column values, tears down.
 *
 *              Image-pathway is INTENTIONALLY excluded per the 0.4
 *              audit gate — the smoke asserts that no image_hit row
 *              is created with image_subsystem='exp'.
 *
 *              Scenarios covered:
 *                A. Public project + Paterson Apparatus + DAQ channels
 *                   measuring Pressure as the first listed. Expected:
 *                   item_hit row with apparatus_type='Paterson Apparatus',
 *                   measurement_type='Pressure', daq_sensor_type set,
 *                   has_samples=TRUE (linked sample seeded),
 *                   has_images=TRUE (linked document seeded),
 *                   project_ispublic=TRUE.
 *                B. Private project + Triaxial + Displacement as
 *                   first measurement. project_ispublic=FALSE.
 *                C. Location: facility lat/lng (text) → parsed into a
 *                   POINT. Coords stored as VARCHAR strings — the
 *                   extractor's regex-validation filter is exercised.
 *                D. Location fallback: experiment with no facility coords
 *                   but a sample with provenance lat/lng → location =
 *                   sample provenance point.
 *                E. has_images = FALSE for an experiment with no
 *                   linked documents.
 *                F. searchtext_tsv U1 keyword search finds unique tokens
 *                   from project notes, apparatus name, AND a measurement
 *                   type that is NOT the scalar primary (proves the
 *                   keyword bag covers the multi-value lossy-scalar
 *                   case).
 *                G. NO image_hit row is created (Exp-image gate).
 *                H. Atomic swap idempotency: re-running gives the same
 *                   row set.
 *                I. Same-id decoy: a second user owns an experiment
 *                   with the SAME id as the primary — must not leak.
 *                J. sync_state NOT updated on --source-userpkey
 *                   partial run.
 *
 *              Self-contained: cleans up before and after. Exits
 *              non-zero on any failure so it can gate CI.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_exp_extractor.php
 *
 * @package    StraboSearch Phase 2 extractors
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$TEST_UPK    = 94507;
$DECOY_UPK   = 94508;
$PREFIX      = 'spsxe94507';   // strabosearch-eXtractor-Exp + isolation upk

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

// Cascade: project DELETE cascades to experiment → apparatus / facility /
// experiment_setup / daq / sample / document (per FK ON DELETE CASCADE).
function exp_drop_for_user($db, $upk) {
	$db->query("DELETE FROM straboexp.project WHERE userpkey = $upk");
}
exp_drop_for_user($db, $TEST_UPK);
exp_drop_for_user($db, $DECOY_UPK);

$db->query("DELETE FROM strabosearch.item_hit  WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK) AND project_subsystem = 'exp'");
$db->query("DELETE FROM strabosearch.image_hit WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK) AND project_subsystem = 'exp'");
$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_exp");
$db->query("DELETE FROM users WHERE pkey IN ($TEST_UPK, $DECOY_UPK)");
$db->query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active)
	VALUES ($TEST_UPK, 'spsxe', 'fixture',
	        'spsxe-fixture-$TEST_UPK@test.strabospot.org', 'x', 'x', false),
	       ($DECOY_UPK, 'spsxe', 'decoy',
	        'spsxe-decoy-$DECOY_UPK@test.strabospot.org', 'x', 'x', false)");

echo '  cleared fixture exp chain + item_hit/image_hit slices + users for upk '
	. $TEST_UPK . ' and decoy ' . $DECOY_UPK . PHP_EOL;

// ===========================================================================
section('1. Seed straboexp fixtures');

/**
 * Seed a project + experiment with optional apparatus / facility /
 * daq_device / channels / sample / document. Returns the experiment pkey.
 *
 * `$measurement_types` is an array of channel header_type strings — the
 * first one is the "primary" scalar; the rest are present in the data
 * but should fall through to the keyword bag only.
 */
function seed_exp($db, $proj_uuid, $proj_name, $upk, $ispublic, $proj_notes,
                  $exp_id, $apparatus_type, $apparatus_name, $apparatus_desc,
                  $facility_name, $facility_lat, $facility_lng,
                  $measurement_types,            // first = primary scalar
                  $sample_name, $sample_lat, $sample_lng,
                  $with_document) {
	$proj_name_esc  = pg_escape_string($proj_name);
	$proj_notes_esc = pg_escape_string($proj_notes);
	$proj_uuid_esc  = pg_escape_string($proj_uuid);
	$pubLit         = $ispublic ? 'true' : 'false';
	$db->query("INSERT INTO straboexp.project
		(userpkey, uuid, name, notes, ispublic)
		VALUES ($upk, '$proj_uuid_esc', '$proj_name_esc', '$proj_notes_esc', $pubLit)");
	$ppk = $db->insert_id;

	$exp_id_esc = pg_escape_string($exp_id);
	$db->query("INSERT INTO straboexp.experiment
		(project_pkey, userpkey, id, json)
		VALUES ($ppk, $upk, '$exp_id_esc', '{}')");
	$epk = $db->insert_id;

	if ($apparatus_type !== null) {
		$db->query("INSERT INTO straboexp.apparatus
			(experiment_pkey, userpkey, name, type, description)
			VALUES ($epk, $upk,
			        '" . pg_escape_string((string)$apparatus_name) . "',
			        '" . pg_escape_string((string)$apparatus_type) . "',
			        '" . pg_escape_string((string)$apparatus_desc) . "')");
		$apk = $db->insert_id;
		if ($with_document) {
			// document attached to apparatus (one of the two has_images paths).
			// straboexp.document has no name column — use type + path.
			$db->query("INSERT INTO straboexp.document
				(apparatus_pkey, userpkey, type, path)
				VALUES ($apk, $upk, 'image', 'fixture apparatus doc path')");
		}
	}

	if ($facility_name !== null) {
		$lat_v = $facility_lat === null ? "''" : "'" . pg_escape_string((string)$facility_lat) . "'";
		$lng_v = $facility_lng === null ? "''" : "'" . pg_escape_string((string)$facility_lng) . "'";
		$db->query("INSERT INTO straboexp.facility
			(experiment_pkey, userpkey, name, latitude, longitude)
			VALUES ($epk, $upk, '" . pg_escape_string($facility_name) . "', $lat_v, $lng_v)");
	}

	if (!empty($measurement_types)) {
		// Create a daq + daq_device, then one channel per measurement type.
		$db->query("INSERT INTO straboexp.daq (experiment_pkey, userpkey) VALUES ($epk, $upk)");
		$daqpk = $db->insert_id;
		$db->query("INSERT INTO straboexp.daq_device
			(daq_pkey, userpkey, name) VALUES ($daqpk, $upk, 'fixture daq device')");
		$ddpk = $db->insert_id;
		foreach ($measurement_types as $idx => $mt) {
			$mt_esc = pg_escape_string($mt);
			$db->query("INSERT INTO straboexp.daq_device_channel
				(daq_device_pkey, userpkey, header_type, type, header_unit, number)
				VALUES ($ddpk, $upk, '$mt_esc', 'Analog Input', 'unit-$idx', '$idx')");
		}
	}

	if ($sample_name !== null) {
		$slat = $sample_lat === null ? 'NULL'
			: "'" . pg_escape_string((string)$sample_lat) . "'";
		$slng = $sample_lng === null ? 'NULL'
			: "'" . pg_escape_string((string)$sample_lng) . "'";
		// strabo_id is a NOT-NULL unique UUID — use gen_random_uuid()
		// (pgcrypto, available on the dev/prod DB).
		$db->query("INSERT INTO straboexp.sample
			(experiment_pkey, userpkey, strabo_id, name, material_name,
			 provenance_loc_latitude, provenance_loc_longitude)
			VALUES ($epk, $upk, gen_random_uuid(),
			        '" . pg_escape_string($sample_name) . "', 'granite',
			        $slat, $slng)");
	}

	return $epk;
}

// ---------------------------------------------------------------------------
// SCENARIO A — Public, Paterson + Pressure-primary + facility coords +
// sample (no provenance coords) + document.
$expA = seed_exp($db,
	$PREFIX . '_proj_pub', 'spsxe Paterson Project', $TEST_UPK, true,
	'UNIQKEY_projnotes some Paterson project notes',
	'EXP-A-001',
	'Paterson Apparatus', 'My Paterson Rig', 'UNIQKEY_apparatusdesc',
	'MIT High Pressure Lab', '42.3601', '-71.0942',
	array('Pressure', 'Displacement', 'Load', 'Temperature'),  // Pressure primary
	'Sample alpha', null, null,
	true);

// ---------------------------------------------------------------------------
// SCENARIO B — Private, Triaxial + Displacement-primary.
$expB = seed_exp($db,
	$PREFIX . '_proj_priv', 'spsxe Triaxial Project', $TEST_UPK, false, '',
	'EXP-B-001',
	'Triaxial (conventional)', 'My Triaxial Rig', '',
	null, null, null,
	array('Displacement', 'Pressure'),
	null, null, null,
	false);

// ---------------------------------------------------------------------------
// SCENARIO D — No facility coords; sample provenance lat/lng IS set →
// location should fall back to sample provenance point.
$expD = seed_exp($db,
	$PREFIX . '_proj_provenance', 'spsxe Provenance Fallback Project', $TEST_UPK, true, '',
	'EXP-D-001',
	'Griggs Apparatus', 'Griggs', '',
	'Some Lab With No Coords', null, null,
	array('Strain'),
	'Sample delta', '34.0522', '-118.2437',  // LA
	false);

// ---------------------------------------------------------------------------
// SCENARIO E — has_images = FALSE (no apparatus document, no setup doc).
$expE = seed_exp($db,
	$PREFIX . '_proj_noimg', 'spsxe No-Images Project', $TEST_UPK, true, '',
	'EXP-E-001',
	'Biaxial', 'Biaxial-1', '',
	null, null, null,
	array('Load'),
	null, null, null,
	false);

// ---------------------------------------------------------------------------
// SCENARIO H — BOTH facility coords AND sample provenance coords set →
// provenance must WIN (priority swapped 2026-08-23: facility-first
// averaged rock provenance with lab addresses into mid-ocean globe
// project centroids).
$expH = seed_exp($db,
	$PREFIX . '_proj_both', 'spsxe Both Locations Project', $TEST_UPK, true, '',
	'EXP-H-001',
	'Griggs Apparatus', 'Griggs Both', '',
	'MIT High Pressure Lab', '42.3601', '-71.0942',
	array('Strain'),
	'Sample beta', '44.0793', '10.0979',
	false);

// ---------------------------------------------------------------------------
// SCENARIO I — Same-id decoy under DECOY_UPK with the SAME experiment.id
// as expA (EXP-A-001). Must not leak into TEST_UPK rows.
$expDecoy = seed_exp($db,
	$PREFIX . '_proj_decoy', 'spsxe DECOY Project', $DECOY_UPK, true,
	'UNIQKEY_decoyprojnotes',
	'EXP-A-001',
	'Other Apparatus', 'Decoy Rig', 'UNIQKEY_decoyapparatusdesc',
	null, null, null,
	array('Other'),
	null, null, null,
	false);

echo "  seeded 5 primary experiments (A/B/D/E/H) + 1 decoy (same exp.id as A)\n";

// ===========================================================================
section('2. Run extractor');

$syncBefore = $db->get_var("SELECT last_full_backfill FROM strabosearch.sync_state WHERE source='exp'");

$cmd = "php /srv/app/www/searchdb/extractors/exp.php --source-userpkey=$TEST_UPK --apply 2>&1";
$out = shell_exec($cmd);
$ok = (strpos($out, 'SWAP FAILED') === false && strpos($out, 'FAIL row') === false);
check('extractor exits clean', $ok, $ok ? '' : substr($out, -400));

// ===========================================================================
section('3. Row presence + project context');

$itemCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'"
);
check('5 item_hit rows for TEST_UPK experiments', $itemCount === 5, "got $itemCount");

$pubCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'
	   AND project_ispublic = TRUE"
);
check('public exp rows: 4', $pubCount === 4, "got $pubCount");

$privCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'
	   AND project_ispublic = FALSE"
);
check('private exp rows: 1', $privCount === 1, "got $privCount");

$itype = $db->get_var(
	"SELECT distinct item_type FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'"
);
check("item_type = 'experiment'", $itype === 'experiment', "got '$itype'");

// ===========================================================================
section('4. Scenario A — Paterson + Pressure-primary + facility loc + doc');

$rowA = $db->get_row(
	"SELECT apparatus_type, measurement_type, daq_sensor_type,
	        has_samples, has_images, has_orientation, has_microstructure, has_strat,
	        ST_AsText(location) AS loc, date_value::text AS dv, project_name
	 FROM strabosearch.item_hit
	 WHERE item_id = 'EXP-A-001' AND project_userpkey = $TEST_UPK"
);
check('A: row found', $rowA !== null);
if ($rowA) {
	check("A: apparatus_type = 'Paterson Apparatus'",
		$rowA->apparatus_type === 'Paterson Apparatus', "got '{$rowA->apparatus_type}'");
	check("A: measurement_type = 'Pressure' (first listed)",
		$rowA->measurement_type === 'Pressure', "got '{$rowA->measurement_type}'");
	check("A: daq_sensor_type = 'Analog Input'",
		$rowA->daq_sensor_type === 'Analog Input', "got '{$rowA->daq_sensor_type}'");
	check('A: has_samples = TRUE',  $rowA->has_samples === 't');
	check('A: has_images = TRUE',   $rowA->has_images  === 't');
	check('A: has_orientation = FALSE',    $rowA->has_orientation    === 'f');
	check('A: has_microstructure = FALSE', $rowA->has_microstructure === 'f');
	check('A: has_strat = FALSE',          $rowA->has_strat          === 'f');
	check('A: location = MIT lab (facility FALLBACK: sample has no provenance coords)',
		strpos((string)$rowA->loc, 'POINT(-71.0942 42.3601)') !== false,
		"got '{$rowA->loc}'");
	check('A: project_name preserved',
		$rowA->project_name === 'spsxe Paterson Project',
		"got '{$rowA->project_name}'");
}

// ===========================================================================
section('5. Scenario B — private + Triaxial + Displacement-primary');

$rowB = $db->get_row(
	"SELECT apparatus_type, measurement_type, project_ispublic, has_samples, has_images
	 FROM strabosearch.item_hit WHERE item_id = 'EXP-B-001' AND project_userpkey = $TEST_UPK"
);
check('B: row found', $rowB !== null);
if ($rowB) {
	check("B: apparatus_type = 'Triaxial (conventional)'",
		$rowB->apparatus_type === 'Triaxial (conventional)',
		"got '{$rowB->apparatus_type}'");
	check("B: measurement_type = 'Displacement' (first listed)",
		$rowB->measurement_type === 'Displacement',
		"got '{$rowB->measurement_type}'");
	check('B: project_ispublic = FALSE', $rowB->project_ispublic === 'f');
	check('B: has_samples = FALSE (no sample seeded)', $rowB->has_samples === 'f');
	check('B: has_images = FALSE (no doc seeded)',      $rowB->has_images  === 'f');
}

// ===========================================================================
section('6. Scenario D — location fallback to sample provenance');

$rowD = $db->get_row(
	"SELECT ST_AsText(location) AS loc FROM strabosearch.item_hit
	 WHERE item_id = 'EXP-D-001' AND project_userpkey = $TEST_UPK"
);
check('D: row found', $rowD !== null);
if ($rowD) {
	check('D: location is sample provenance LA point (-118.24, 34.05)',
		strpos((string)$rowD->loc, 'POINT(-118.2437 34.0522)') !== false,
		"got '{$rowD->loc}'");
}

// ===========================================================================
section('6b. Scenario H — sample provenance WINS over facility coords');

$rowH = $db->get_row(
	"SELECT ST_AsText(location) AS loc FROM strabosearch.item_hit
	 WHERE item_id = 'EXP-H-001' AND project_userpkey = $TEST_UPK"
);
check('H: row found', $rowH !== null);
if ($rowH) {
	check('H: location = sample provenance Carrara point, NOT the MIT facility',
		strpos((string)$rowH->loc, 'POINT(10.0979 44.0793)') !== false,
		"got '{$rowH->loc}'");
}

// ===========================================================================
section('7. Scenario E — has_images FALSE when no docs');

$rowE = $db->get_row(
	"SELECT has_images, ST_AsText(location) AS loc
	 FROM strabosearch.item_hit WHERE item_id = 'EXP-E-001' AND project_userpkey = $TEST_UPK"
);
check('E: row found', $rowE !== null);
if ($rowE) {
	check('E: has_images = FALSE (no apparatus or setup doc)',
		$rowE->has_images === 'f', "got '{$rowE->has_images}'");
	check('E: location = NULL (no facility, no sample provenance)',
		$rowE->loc === null,
		'got ' . var_export($rowE->loc, true));
}

// ===========================================================================
section('8. Scenario F — searchtext_tsv U1 keyword search');

$projNotesHit = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'
	   AND searchtext_tsv @@ to_tsquery('uniqkey_projnotes')"
);
check('searchtext finds unique project-notes token (1 hit)',
	$projNotesHit === 1, "got $projNotesHit");

$apparatusDescHit = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'
	   AND searchtext_tsv @@ to_tsquery('uniqkey_apparatusdesc')"
);
check('searchtext finds unique apparatus-description token (1 hit)',
	$apparatusDescHit === 1, "got $apparatusDescHit");

// Critical: secondary measurement keyword search. expA has primary
// measurement_type='Pressure' but ALSO measures Load, Displacement,
// Temperature. The keyword "load" must surface expA even though its
// scalar measurement_type is Pressure.
$loadHit = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'
	   AND item_id = 'EXP-A-001'
	   AND searchtext_tsv @@ to_tsquery('load')"
);
check('searchtext finds secondary measurement (Load) on Pressure-primary expA',
	$loadHit === 1, "got $loadHit (proves the bag carries every distinct header_type)");

$tempHit = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'
	   AND item_id = 'EXP-A-001'
	   AND searchtext_tsv @@ to_tsquery('temperature')"
);
check('searchtext finds secondary measurement (Temperature) on expA',
	$tempHit === 1, "got $tempHit");

// ===========================================================================
section('9. Scenario G — NO image_hit rows for exp (Phase 0.4 gate)');

$expImageRows = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'"
);
check('no image_hit rows with project_subsystem=exp', $expImageRows === 0,
	"got $expImageRows (Phase 0.4 audit excludes Exp images from v1)");

$expImageRowsBySubsystem = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit
	 WHERE project_userpkey = $TEST_UPK AND image_subsystem = 'exp'"
);
check('no image_hit rows with image_subsystem=exp', $expImageRowsBySubsystem === 0,
	"got $expImageRowsBySubsystem");

// ===========================================================================
section('10. Idempotency — re-run produces same row set');

shell_exec($cmd);
$itemCount2 = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'"
);
check('idempotent re-run: still 5 item_hit rows', $itemCount2 === 5, "got $itemCount2");

$rowA2 = $db->get_row(
	"SELECT apparatus_type FROM strabosearch.item_hit
	 WHERE item_id = 'EXP-A-001' AND project_userpkey = $TEST_UPK"
);
check('idempotent: A still has apparatus_type = Paterson Apparatus',
	$rowA2 && $rowA2->apparatus_type === 'Paterson Apparatus');

// ===========================================================================
section('11. Scenario I — same-id decoy must NOT cross-contaminate');

$decoyLeak = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'
	   AND searchtext_tsv @@ to_tsquery('uniqkey_decoyprojnotes')"
);
check('decoy project-notes token NOT in TEST_UPK searchtext',
	$decoyLeak === 0, "got $decoyLeak");

$decoyApparatusLeak = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'
	   AND searchtext_tsv @@ to_tsquery('uniqkey_decoyapparatusdesc')"
);
check('decoy apparatus-desc token NOT in TEST_UPK searchtext',
	$decoyApparatusLeak === 0, "got $decoyApparatusLeak");

$testItemCount = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE project_userpkey = $TEST_UPK AND project_subsystem = 'exp'"
);
check('TEST_UPK row count unaffected by decoy (still 5)',
	$testItemCount === 5, "got $testItemCount");

// ===========================================================================
section('12. sync_state NOT updated on partial run');

$syncAfter = $db->get_var("SELECT last_full_backfill FROM strabosearch.sync_state WHERE source='exp'");
check('sync_state.exp last_full_backfill unchanged after --source-userpkey',
	$syncBefore === $syncAfter,
	'before=' . var_export($syncBefore, true) . ' after=' . var_export($syncAfter, true));

// ===========================================================================
section('13. Cleanup');

exp_drop_for_user($db, $TEST_UPK);
exp_drop_for_user($db, $DECOY_UPK);
$db->query("DELETE FROM strabosearch.item_hit  WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK) AND project_subsystem = 'exp'");
$db->query("DELETE FROM strabosearch.image_hit WHERE project_userpkey IN ($TEST_UPK, $DECOY_UPK) AND project_subsystem = 'exp'");
$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_exp");
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
